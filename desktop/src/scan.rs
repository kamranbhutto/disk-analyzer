//! Parallel folder scan. Sizes are the space files really use on disk (allocated
//! blocks, like `du`), plus the apparent size (the byte length, like `ls -l`).
//! Symlinks are never followed, and hard-linked files are counted once.

use std::collections::{HashMap, HashSet, VecDeque};
use std::fs;
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering::Relaxed};
use std::sync::Mutex;
use std::time::Instant;

use rayon::prelude::*;
use serde::Serialize;
use serde_json::{json, Value};

/// Virtual file systems that only waste time (and can hang) when scanned from `/`.
const SKIP: [&str; 4] = ["/proc", "/sys", "/dev", "/run"];
const NO_PARENT: u32 = u32::MAX;

#[derive(Default)]
pub struct Progress {
    pub files: AtomicU64,
    pub dirs: AtomicU64,
    pub bytes: AtomicU64,
    pub errors: AtomicU64,
    pub cancel: AtomicBool,
}

/// Temporary tree built during the scan.
struct Node {
    name: String,
    size: u64,
    apparent: u64,
    files: u64,
    dirs: u64,
    /// Last modified, seconds since 1970.
    modified: i64,
    is_dir: bool,
    children: Vec<Node>,
}

/// Flat entry. Children of an entry are stored next to each other, biggest first.
pub struct Entry {
    pub name: Box<str>,
    pub parent: u32,
    pub size: u64,
    pub apparent: u64,
    pub files: u64,
    pub dirs: u64,
    pub modified: i64,
    pub is_dir: bool,
    pub first_child: u32,
    pub child_count: u32,
}

#[derive(Serialize)]
pub struct TypeStat {
    pub ext: String,
    pub size: u64,
    pub apparent: u64,
    pub files: u64,
}

pub struct Tree {
    pub root: PathBuf,
    pub entries: Vec<Entry>,
    pub elapsed_ms: u64,
    pub errors: u64,
    /// True if the user stopped the scan early.
    pub partial: bool,
    pub types: Vec<TypeStat>,
}

struct Ctx<'a> {
    dev: Option<u64>,
    progress: &'a Progress,
    seen: Mutex<HashSet<(u64, u64)>>,
}

pub fn scan(root: &Path, one_filesystem: bool, progress: &Progress) -> Result<Tree, String> {
    let start = Instant::now();
    let md = fs::metadata(root).map_err(|e| format!("{}: {e}", root.display()))?;
    if !md.is_dir() {
        return Err(format!("{} is not a folder", root.display()));
    }
    let ctx = Ctx { dev: one_filesystem.then(|| md.dev()), progress, seen: Mutex::default() };
    let node = scan_dir(root, root.display().to_string(), md.blocks() * 512, md.mtime(), &ctx);
    let entries = flatten(node);
    Ok(Tree {
        root: root.to_path_buf(),
        types: types(&entries),
        entries,
        elapsed_ms: start.elapsed().as_millis() as u64,
        errors: progress.errors.load(Relaxed),
        partial: progress.cancel.load(Relaxed),
    })
}

fn scan_dir(path: &Path, name: String, own_size: u64, modified: i64, ctx: &Ctx) -> Node {
    let p = ctx.progress;
    p.dirs.fetch_add(1, Relaxed);
    p.bytes.fetch_add(own_size, Relaxed);
    let mut node = Node { name, size: own_size, apparent: 0, files: 0, dirs: 0, modified, is_dir: true, children: Vec::new() };
    if p.cancel.load(Relaxed) {
        return node;
    }
    let Ok(read) = fs::read_dir(path) else {
        p.errors.fetch_add(1, Relaxed); // usually "permission denied"
        return node;
    };
    let mut subdirs = Vec::new();
    for entry in read {
        if p.cancel.load(Relaxed) { break; }
        // DirEntry::metadata does not follow symlinks.
        let Some((entry, md)) = entry.ok().and_then(|e| e.metadata().ok().map(|m| (e, m))) else {
            p.errors.fetch_add(1, Relaxed);
            continue;
        };
        let name = entry.file_name().to_string_lossy().into_owned();
        if md.is_dir() {
            let child = entry.path();
            let other_fs = ctx.dev.is_some_and(|d| md.dev() != d);
            if !other_fs && !SKIP.iter().any(|s| child == Path::new(s)) {
                subdirs.push((child, name, md.blocks() * 512, md.mtime()));
            }
            continue;
        }
        let (mut size, mut apparent) = (md.blocks() * 512, md.len());
        if md.nlink() > 1 && !ctx.seen.lock().unwrap().insert((md.dev(), md.ino())) {
            (size, apparent) = (0, 0); // another hard link to a file already counted
        }
        p.files.fetch_add(1, Relaxed);
        p.bytes.fetch_add(size, Relaxed);
        node.children.push(Node { name, size, apparent, files: 1, dirs: 0, modified: md.mtime(), is_dir: false, children: Vec::new() });
    }
    let dirs: Vec<Node> = subdirs.into_par_iter().map(|(path, name, size, modified)| scan_dir(&path, name, size, modified, ctx)).collect();
    node.children.extend(dirs);
    for c in &node.children {
        node.size += c.size;
        node.apparent += c.apparent;
        node.files += c.files;
        node.dirs += c.dirs + c.is_dir as u64;
    }
    node
}

fn flatten(root: Node) -> Vec<Entry> {
    fn entry(n: &Node, parent: u32) -> Entry {
        Entry {
            name: n.name.as_str().into(),
            parent,
            size: n.size,
            apparent: n.apparent,
            files: n.files,
            dirs: n.dirs,
            modified: n.modified,
            is_dir: n.is_dir,
            first_child: 0,
            child_count: 0,
        }
    }
    let mut entries = vec![entry(&root, NO_PARENT)];
    let mut queue = VecDeque::from([(0u32, root.children)]);
    while let Some((id, mut kids)) = queue.pop_front() {
        kids.sort_by(|a, b| b.size.cmp(&a.size).then_with(|| a.name.cmp(&b.name)));
        entries[id as usize].first_child = entries.len() as u32;
        entries[id as usize].child_count = kids.len() as u32;
        for mut k in kids {
            let cid = entries.len() as u32;
            entries.push(entry(&k, id));
            if !k.children.is_empty() {
                queue.push_back((cid, std::mem::take(&mut k.children)));
            }
        }
    }
    entries
}

/// Wildcard match of the whole text: `*` = any characters, `?` = one character.
pub fn wildcard(pat: &[u8], text: &[u8]) -> bool {
    let (mut p, mut t, mut star, mut mark) = (0, 0, None, 0);
    while t < text.len() {
        if p < pat.len() && (pat[p] == b'?' || pat[p] == text[t]) {
            p += 1;
            t += 1;
        } else if p < pat.len() && pat[p] == b'*' {
            star = Some(p);
            mark = t;
            p += 1;
        } else if let Some(s) = star {
            p = s + 1;
            mark += 1;
            t = mark;
        } else {
            return false;
        }
    }
    pat[p..].iter().all(|&c| c == b'*')
}

pub fn extension(name: &str) -> String {
    match name.rsplit_once('.') {
        Some((stem, ext)) if !stem.is_empty() && !ext.is_empty() && ext.len() <= 10 => ext.to_lowercase(),
        _ => "(none)".into(),
    }
}

fn types(entries: &[Entry]) -> Vec<TypeStat> {
    let mut map: HashMap<String, (u64, u64, u64)> = HashMap::new();
    for e in entries.iter().filter(|e| !e.is_dir) {
        let t = map.entry(extension(&e.name)).or_default();
        t.0 += e.size;
        t.1 += e.apparent;
        t.2 += 1;
    }
    let mut out: Vec<TypeStat> =
        map.into_iter().map(|(ext, (size, apparent, files))| TypeStat { ext, size, apparent, files }).collect();
    out.sort_by_key(|t| std::cmp::Reverse(t.size));
    out.truncate(100);
    out
}

impl Tree {
    pub fn get(&self, id: u32) -> Option<&Entry> {
        self.entries.get(id as usize)
    }

    pub fn children(&self, id: u32) -> impl Iterator<Item = u32> {
        let e = &self.entries[id as usize];
        e.first_child..e.first_child + e.child_count
    }

    /// Ids from the root down to `id`.
    pub fn chain(&self, mut id: u32) -> Vec<u32> {
        let mut out = vec![id];
        while self.entries[id as usize].parent != NO_PARENT {
            id = self.entries[id as usize].parent;
            out.push(id);
        }
        out.reverse();
        out
    }

    pub fn path(&self, id: u32) -> PathBuf {
        let mut p = self.root.clone();
        for &i in &self.chain(id)[1..] {
            p.push(&*self.entries[i as usize].name);
        }
        p
    }

    pub fn summary(&self, id: u32) -> Value {
        let e = &self.entries[id as usize];
        json!({
            "id": id,
            "name": &*e.name,
            "size": e.size,
            "apparent": e.apparent,
            "files": e.files,
            "dirs": e.dirs,
            "modified": e.modified,
            "is_dir": e.is_dir,
            "has_children": e.child_count > 0,
        })
    }

    /// Compact nested map for the treemap: `[id, size, kind, name, children?]`, where
    /// kind is -1 for a folder, the index into `types` for a file of a listed type, or
    /// `types.len()` for any other file. Items smaller than `min` bytes are left out
    /// (the page draws the space they leave as "smaller items").
    pub fn compact_map(&self, id: u32, min: u64, kinds: &HashMap<&str, usize>) -> Value {
        let e = &self.entries[id as usize];
        if !e.is_dir {
            let kind = kinds.get(extension(&e.name).as_str()).copied().unwrap_or(self.types.len());
            return json!([id, e.size, kind, &*e.name]);
        }
        let kids: Vec<Value> = self
            .children(id)
            .take_while(|&c| self.entries[c as usize].size >= min.max(1))
            .take(3000)
            .map(|c| self.compact_map(c, min, kinds))
            .collect();
        json!([id, e.size, -1, &*e.name, kids])
    }

}

#[cfg(test)]
mod tests {
    use super::*;

    fn temp_dir(tag: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("disk-analyzer-test-{tag}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&d);
        fs::create_dir_all(&d).unwrap();
        d
    }

    #[test]
    fn sizes_add_up_and_biggest_comes_first() {
        let d = temp_dir("sizes");
        fs::create_dir_all(d.join("a/b")).unwrap();
        fs::write(d.join("small.txt"), vec![0u8; 10]).unwrap();
        fs::write(d.join("a/big.bin"), vec![1u8; 100_000]).unwrap();
        fs::write(d.join("a/b/mid.TXT"), vec![1u8; 5_000]).unwrap();

        let t = scan(&d, true, &Progress::default()).unwrap();
        let root = &t.entries[0];
        assert_eq!(root.apparent, 105_010);
        assert_eq!(root.files, 3);
        assert_eq!(root.dirs, 2);
        assert!(root.modified > 0);
        let kinds = HashMap::from([("bin", 0)]);
        let map = t.compact_map(0, 5000, &kinds); // drops small.txt (one 4 KiB block on disk)
        assert_eq!(map[2], -1);
        assert_eq!(map[4].as_array().unwrap().len(), 1);
        assert_eq!(map[4][0][4][0][2], 0); // big.bin is type 0
        let kids: Vec<&str> = t.children(0).map(|c| &*t.entries[c as usize].name).collect();
        assert_eq!(kids, ["a", "small.txt"]);

        let big = (0..t.entries.len() as u32).max_by_key(|&i| t.entries[i as usize].size * !t.entries[i as usize].is_dir as u64).unwrap();
        assert_eq!(&*t.entries[big as usize].name, "big.bin");
        assert_eq!(t.path(big), d.join("a/big.bin"));
        assert_eq!(t.chain(big).len(), 3);
        assert!(t.types.iter().any(|s| s.ext == "txt" && s.files == 2)); // extension case ignored
        fs::remove_dir_all(&d).unwrap();
    }

    #[test]
    fn hard_links_count_once_and_symlinks_are_not_followed() {
        let d = temp_dir("links");
        fs::create_dir(d.join("sub")).unwrap();
        fs::write(d.join("sub/data"), vec![0u8; 50_000]).unwrap();
        fs::hard_link(d.join("sub/data"), d.join("copy")).unwrap();
        std::os::unix::fs::symlink(d.join("sub"), d.join("loop")).unwrap();

        let t = scan(&d, true, &Progress::default()).unwrap();
        assert_eq!(t.entries[0].apparent, 50_000 + fs::symlink_metadata(d.join("loop")).unwrap().len());
        fs::remove_dir_all(&d).unwrap();
    }

    #[test]
    fn cancellation_returns_partial_results_and_files_are_rejected() {
        let d = temp_dir("cancel");
        fs::write(d.join("data"), vec![1u8; 100]).unwrap();
        let progress = Progress::default();
        progress.cancel.store(true, Relaxed);
        let tree = scan(&d, true, &progress).unwrap();
        assert!(tree.partial);
        assert_eq!(tree.entries[0].files, 0);
        assert!(scan(&d.join("data"), true, &Progress::default()).is_err());
        fs::remove_dir_all(d).unwrap();
    }

    #[test]
    fn wildcards() {
        assert!(wildcard(b"*.mp3", b"song.mp3"));
        assert!(!wildcard(b"*.mp3", b"song.mp3.part"));
        assert!(wildcard(b"*/downloads/*.iso", b"/home/a/downloads/x.iso"));
        assert!(wildcard(b"img_????.jpg", b"img_0042.jpg"));
        assert!(!wildcard(b"img_????.jpg", b"img_42.jpg"));
        assert!(wildcard(b"*", b""));
    }

    #[test]
    fn extensions() {
        assert_eq!(extension("photo.JPG"), "jpg");
        assert_eq!(extension(".bashrc"), "(none)");
        assert_eq!(extension("Makefile"), "(none)");
        assert_eq!(extension("a.tar.gz"), "gz");
    }
}
