mod scan;

use axum::{extract::{Query, State}, http::{header, StatusCode, Request}, middleware::{self, Next}, response::{Html, IntoResponse, Response}, routing::{get, post}, Json, Router};
use serde::Deserialize;
use std::collections::HashMap;
use serde_json::{json, Value};
use std::{path::PathBuf, sync::{Arc, Mutex, atomic::Ordering::Relaxed}};

#[derive(Default)]
struct Session { running: bool, progress: Arc<scan::Progress>, tree: Option<Arc<scan::Tree>>, error: Option<String> }
type App = Arc<Mutex<Session>>;
type ApiError = (StatusCode, Json<Value>);
fn error(code: StatusCode, message: impl ToString) -> ApiError { (code, Json(json!({"error":message.to_string()}))) }

// Keep local filesystem information available only to this loopback page.
/// Address the server listens on; set PORT to use another port.
fn addr() -> &'static str {
    static ADDR: std::sync::OnceLock<String> = std::sync::OnceLock::new();
    ADDR.get_or_init(|| format!("127.0.0.1:{}", std::env::var("PORT").ok().and_then(|p| p.parse::<u16>().ok()).unwrap_or(8790)))
}
async fn local_only(req: Request<axum::body::Body>, next: Next) -> Response {
    let port = &addr()[addr().rfind(':').unwrap()..];
    let allowed = |v: &str| v == addr() || v.strip_prefix("localhost") == Some(port);
    let host = req.headers().get(header::HOST).and_then(|v| v.to_str().ok());
    let origin = req.headers().get(header::ORIGIN).and_then(|v| v.to_str().ok());
    if !host.is_some_and(allowed) || origin.is_some_and(|v| !v.strip_prefix("http://").is_some_and(allowed)) {
        return error(StatusCode::FORBIDDEN, format!("Open this app at http://{}", addr())).into_response();
    }
    let mut response = next.run(req).await;
    response.headers_mut().insert(header::CACHE_CONTROL, "no-store".parse().unwrap());
    response.headers_mut().insert(header::X_CONTENT_TYPE_OPTIONS, "nosniff".parse().unwrap());
    response
}

#[derive(Deserialize)]
struct Start { path: PathBuf, #[serde(default = "yes")] one_filesystem: bool }
fn yes() -> bool { true }
async fn start(State(app): State<App>, Json(input): Json<Start>) -> Result<Json<Value>, ApiError> {
    if !input.path.is_absolute() { return Err(error(StatusCode::BAD_REQUEST, "Enter an absolute folder path.")); }
    let path = std::fs::canonicalize(&input.path).map_err(|e| error(StatusCode::BAD_REQUEST,e))?;
    if !path.is_dir() { return Err(error(StatusCode::BAD_REQUEST,"Choose a folder, not a file.")); }
    let progress = Arc::new(scan::Progress::default());
    {
        let mut s = app.lock().unwrap();
        if s.running { return Err(error(StatusCode::CONFLICT,"A scan is running. Stop it before starting another.")); }
        *s = Session { running:true, progress:progress.clone(), tree:None, error:None };
    }
    tokio::spawn(async move {
        let result = tokio::task::spawn_blocking(move || scan::scan(&path,input.one_filesystem,&progress)).await;
        let mut s = app.lock().unwrap();
        match result {
            Ok(Ok(tree)) => s.tree = Some(Arc::new(tree)),
            Ok(Err(e)) => s.error = Some(e),
            Err(e) => s.error = Some(format!("Scan stopped unexpectedly: {e}")),
        }
        s.running = false;
    });
    Ok(Json(json!({"running":true})))
}
async fn cancel(State(app): State<App>) -> Json<Value> {
    app.lock().unwrap().progress.cancel.store(true,Relaxed);
    Json(json!({"ok":true}))
}
async fn status(State(app): State<App>) -> Json<Value> {
    let s = app.lock().unwrap(); let p = &s.progress;
    Json(json!({"running":s.running,"files":p.files.load(Relaxed),"dirs":p.dirs.load(Relaxed),"bytes":p.bytes.load(Relaxed),"errors":p.errors.load(Relaxed),"error":s.error,"ready":s.tree.is_some(),"root":s.tree.as_ref().map(|t|t.root.clone()),"partial":s.tree.as_ref().is_some_and(|t|t.partial),"elapsed_ms":s.tree.as_ref().map_or(0,|t|t.elapsed_ms)}))
}
#[derive(Deserialize)]
struct Selection { #[serde(default)] id:u32 }
fn snapshot(app: &App) -> Result<Arc<scan::Tree>,ApiError> {
    app.lock().unwrap().tree.clone().ok_or_else(||error(StatusCode::CONFLICT,"Scan a folder first."))
}
fn check(t:&scan::Tree, id:u32) -> Result<&scan::Entry,ApiError> {
    t.get(id).ok_or_else(||error(StatusCode::NOT_FOUND,"Item not found."))
}

/// Mounted drives as reported by `df` (pseudo filesystems left out).
fn df(args:&[&str]) -> Vec<Value> {
    let out=std::process::Command::new("df").args(["-B1","--output=source,size,used,avail,target","-x","tmpfs","-x","devtmpfs","-x","squashfs","-x","overlay","-x","efivarfs"]).args(args).output();
    let Ok(out)=out else { return vec![] };
    String::from_utf8_lossy(&out.stdout).lines().skip(1).filter_map(|l| {
        let mut parts=l.split_whitespace();
        let source=parts.next()?;
        let n:Vec<u64>=(0..3).map(|_|parts.next().and_then(|v|v.parse().ok())).collect::<Option<_>>()?;
        let target=parts.collect::<Vec<_>>().join(" ");
        Some(json!({"source":source,"total":n[0],"used":n[1],"free":n[2],"mount":target}))
    }).collect()
}
async fn drives() -> Json<Value> {
    let mut list=df(&[]);
    if let Ok(home)=std::env::var("HOME") { list.insert(0,json!({"mount":home,"home":true})); }
    Json(json!(list))
}

async fn info(State(app): State<App>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?;
    let mut root=t.summary(0); root["path"]=json!(t.root);
    let disk=df(&[&t.root.to_string_lossy()]).into_iter().next();
    Ok(Json(json!({"root":root,"types":t.types,"disk":disk,"elapsed_ms":t.elapsed_ms,"errors":t.errors,"partial":t.partial})))
}
async fn children(State(app): State<App>, Query(q):Query<Selection>) -> Result<Json<Value>,ApiError> {
    const LIMIT:usize=5000;
    let t=snapshot(&app)?; let e=check(&t,q.id)?;
    let kids:Vec<_>=t.children(q.id).take(LIMIT).map(|i|t.summary(i)).collect();
    Ok(Json(json!({"children":kids,"more":(e.child_count as usize).saturating_sub(LIMIT)})))
}
async fn node(State(app): State<App>, Query(q):Query<Selection>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?; check(&t,q.id)?;
    let mut v=t.summary(q.id); v["path"]=json!(t.path(q.id)); v["chain"]=json!(t.chain(q.id));
    Ok(Json(v))
}
async fn map(State(app): State<App>, Query(q):Query<Selection>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?; let e=check(&t,q.id)?;
    let kinds:std::collections::HashMap<&str,usize>=t.types.iter().enumerate().map(|(i,s)|(s.ext.as_str(),i)).collect();
    // About one item per 30 pixels of a full-HD map; smaller ones become shading.
    Ok(Json(t.compact_map(q.id,e.size/60_000,&kinds)))
}

#[derive(Deserialize)]
struct FileQuery {
    #[serde(default)] q:String, #[serde(default)] whole_path:bool, #[serde(default)] folders:bool,
    #[serde(default="max_files")] max:usize,
    /// "" = off, "name_size" or "name_size_date": how duplicate files are recognised.
    #[serde(default)] dups:String, #[serde(default)] dups_only:bool,
}
fn max_files() -> usize { 1000 }
/// File View: the biggest files (or folders) whose name or full path matches the search
/// (plain text, or a wildcard pattern such as `*.mp3`), optionally with duplicate info.
async fn files(State(app): State<App>, Query(q):Query<FileQuery>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?;
    let max=q.max.clamp(1,100_000);
    let result=tokio::task::spawn_blocking(move || {
        let needle=q.q.trim().to_lowercase();
        let glob=needle.contains(['*','?']);
        let matches_text=|text:&str| if glob { scan::wildcard(needle.as_bytes(),text.as_bytes()) } else { text.contains(&needle) };
        // Parents always come before children, so folder paths can be built in one pass.
        let dir_paths:Vec<Option<String>>=if q.whole_path && !needle.is_empty() {
            let mut v:Vec<Option<String>>=Vec::with_capacity(t.entries.len());
            for (i,e) in t.entries.iter().enumerate() {
                v.push(e.is_dir.then(|| if i==0 { t.root.to_string_lossy().to_lowercase() } else {
                    format!("{}/{}",v[e.parent as usize].as_deref().unwrap_or("").trim_end_matches('/'),e.name.to_lowercase())
                }));
            }
            v
        } else { vec![] };
        let folders=q.folders && q.dups.is_empty();
        let mut ids:Vec<u32>=(1..t.entries.len() as u32).filter(|&i| {
            let e=&t.entries[i as usize];
            if e.is_dir!=folders { return false; }
            if needle.is_empty() { return true; }
            if q.whole_path {
                if let Some(p)=&dir_paths[i as usize] { return matches_text(p); }
                let parent=dir_paths[e.parent as usize].as_deref().unwrap_or("").trim_end_matches('/');
                return matches_text(&format!("{parent}/{}",e.name.to_lowercase()));
            }
            matches_text(&e.name.to_lowercase())
        }).collect();
        // Duplicates: same name and size (and modified time if asked). Empty files are ignored.
        let groups:HashMap<u32,(u32,usize)>=if q.dups.is_empty() { HashMap::new() } else {
            let by_date=q.dups=="name_size_date";
            let mut by_key:HashMap<(&str,u64,i64),Vec<u32>>=HashMap::new();
            for (i,e) in t.entries.iter().enumerate() {
                if !e.is_dir && e.apparent>0 { by_key.entry((&*e.name,e.apparent,if by_date {e.modified} else {0})).or_default().push(i as u32); }
            }
            let mut out=HashMap::new();
            for (g,list) in by_key.into_values().filter(|l|l.len()>1).enumerate() {
                for &i in &list { out.insert(i,(g as u32,list.len())); }
            }
            out
        };
        if q.dups_only { ids.retain(|i|groups.contains_key(i)); }
        let matches=ids.len();
        let size=|i:u32| t.entries[i as usize].size;
        if q.dups_only {
            // Keep copies of the same file next to each other, biggest groups first.
            ids.sort_by(|a,b| size(*b).cmp(&size(*a)).then(groups[a].0.cmp(&groups[b].0)).then(a.cmp(b)));
            ids.truncate(max);
        } else {
            let by_size=|a:&u32,b:&u32| size(*b).cmp(&size(*a));
            if ids.len()>max { ids.select_nth_unstable_by(max,by_size); ids.truncate(max); }
            ids.sort_by(by_size);
        }
        let list:Vec<_>=ids.iter().map(|&i| {
            let mut v=t.summary(i); v["path"]=json!(t.path(i));
            if let Some(&(g,n))=groups.get(&i) { v["dup_group"]=json!(g); v["dup_count"]=json!(n-1); v["dup_size"]=json!(t.entries[i as usize].apparent*(n as u64-1)); }
            v
        }).collect();
        json!({"files":list,"matches":matches,"root_size":t.entries[0].size})
    }).await.map_err(|e|error(StatusCode::INTERNAL_SERVER_ERROR,e))?;
    Ok(Json(result))
}

/// Moves a file or folder to the desktop Trash (it can be restored from there).
async fn trash(State(app): State<App>, Json(q):Json<Selection>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?; check(&t,q.id)?;
    if q.id==0 { return Err(error(StatusCode::BAD_REQUEST,"The scanned folder itself cannot be moved to Trash here.")); }
    let path=t.path(q.id);
    let out=tokio::process::Command::new("gio").arg("trash").arg("--").arg(&path).output().await.map_err(|e|error(StatusCode::INTERNAL_SERVER_ERROR,format!("gio: {e}")))?;
    if !out.status.success() {
        return Err(error(StatusCode::BAD_REQUEST,format!("Could not move to Trash: {}",String::from_utf8_lossy(&out.stderr).trim())));
    }
    Ok(Json(json!({"ok":true,"path":path})))
}

/// Shows a folder (or the folder holding a file) in the system file manager.
async fn open(State(app): State<App>, Json(q):Json<Selection>) -> Result<Json<Value>,ApiError> {
    let t=snapshot(&app)?; let e=check(&t,q.id)?;
    let path=if e.is_dir { t.path(q.id) } else { t.path(e.parent) };
    std::process::Command::new("xdg-open").arg(path).spawn().map_err(|e|error(StatusCode::INTERNAL_SERVER_ERROR,e))?;
    Ok(Json(json!({"ok":true})))
}
fn csv_cell(s:&str) -> String {
    let escaped=s.replace('"',"\"\"");
    // Spreadsheet formula protection also applies to locally supplied filenames.
    let prefix=if s.starts_with(['=','+','-','@','\t','\r']) { "'" } else { "" };
    format!("\"{prefix}{escaped}\"")
}
fn csv(t:&scan::Tree) -> String {
    let mut csv=String::from("Path,Type,Allocated bytes,Apparent bytes,Files,Folders,Modified\r\n");
    for (id,e) in t.entries.iter().enumerate() {
        csv.push_str(&format!("{},{},{},{},{},{},{}\r\n",csv_cell(&t.path(id as u32).to_string_lossy()),if e.is_dir {"Folder"} else {"File"},e.size,e.apparent,e.files,e.dirs,e.modified));
    }
    csv
}
async fn export(State(app):State<App>) -> Result<Response,ApiError> {
    let t=snapshot(&app)?;
    Ok(([(header::CONTENT_TYPE,"text/csv; charset=utf-8"),(header::CONTENT_DISPOSITION,"attachment; filename=\"disk-usage.csv\"")],csv(&t)).into_response())
}

/// `disk-analyzer --export <folder> <file.csv>` scans without opening the app.
fn export_cli(args:&[String]) -> std::process::ExitCode {
    let [folder,out]=args else { eprintln!("Usage: disk-analyzer --export <folder> <file.csv>"); return std::process::ExitCode::from(2) };
    let tree=std::fs::canonicalize(folder).map_err(|e|format!("{folder}: {e}")).and_then(|p|scan::scan(&p,true,&scan::Progress::default()));
    match tree.and_then(|t| std::fs::write(out,csv(&t)).map(|_|t).map_err(|e|format!("{out}: {e}"))) {
        Ok(t) => { eprintln!("Wrote {} items ({} bytes allocated) to {out}",t.entries.len(),t.entries[0].size); std::process::ExitCode::SUCCESS }
        Err(e) => { eprintln!("{e}"); std::process::ExitCode::FAILURE }
    }
}
fn main() -> std::process::ExitCode {
    let args:Vec<String>=std::env::args().skip(1).collect();
    if args.first().map(String::as_str)==Some("--export") { return export_cli(&args[1..]); }
    tokio::runtime::Runtime::new().expect("tokio runtime").block_on(serve());
    std::process::ExitCode::SUCCESS
}
async fn serve() {
    let app=Router::new().route("/",get(||async{Html(include_str!("../web/index.html"))}))
        .route("/api/scan",post(start)).route("/api/cancel",post(cancel)).route("/api/status",get(status))
        .route("/api/drives",get(drives)).route("/api/info",get(info)).route("/api/children",get(children))
        .route("/api/node",get(node)).route("/api/map",get(map)).route("/api/files",get(files))
        .route("/api/open",post(open)).route("/api/trash",post(trash)).route("/api/export",get(export))
        .with_state(App::default()).layer(middleware::from_fn(local_only));
    let listener=tokio::net::TcpListener::bind(addr()).await.unwrap_or_else(|e| panic!("Could not open {}: {e} (another copy may be running; set PORT to use another port)", addr()));
    println!("Advanced Disk Analyzer · http://{}", addr());
    axum::serve(listener,app).await.unwrap();
}
