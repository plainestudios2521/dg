<?php
/**
 * Dream Greener — Org Chart Admin Panel
 * Single-file CMS: auth, CRUD, photo upload, publish
 */

// ─── Configuration ───────────────────────────────────────────────────────────
define('DATA_FILE',    __DIR__ . '/../data/org.json');
define('TEMPLATE_FILE',__DIR__ . '/../template.html');
define('OUTPUT_FILE',  __DIR__ . '/../index.html');
define('UPLOAD_DIR',   __DIR__ . '/../data/uploads/');
define('MAX_UPLOAD',   5 * 1024 * 1024); // 5 MB
define('SESSION_TIMEOUT', 3600); // 1 hour

// Admin password — change this value, then run on server to get hash:
//   php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"
// Then replace ADMIN_PASSWORD with: define('ADMIN_HASH', '$2y$10$...');
define('ADMIN_PASSWORD', 'chris');

// Session hardening
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

// ─── Security headers ───────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// ─── CSRF helpers ───────────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ─── Auth helpers ────────────────────────────────────────────────────────────
function isLoggedIn(): bool {
    if (empty($_SESSION['admin_logged_in'])) return false;
    if (time() - ($_SESSION['admin_last_activity'] ?? 0) > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    $_SESSION['admin_last_activity'] = time();
    return true;
}

// ─── API router (JSON endpoints) ─────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }

    $action = $_GET['api'];

    // CSRF check on all state-changing endpoints
    if ($action !== 'load' && !verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['error'=>'Invalid CSRF token']);
        exit;
    }

    // ── Load data ──
    if ($action === 'load') {
        echo file_get_contents(DATA_FILE);
        exit;
    }

    // ── Save person ──
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['id'])) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid input']);
            exit;
        }
        $data = loadData();
        $found = updateNode($data['tree'], $input['id'], $input);
        if (!$found) {
            http_response_code(404);
            echo json_encode(['error'=>'Person not found']);
            exit;
        }
        saveData($data);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Add person ──
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $parentId = $input['parentId'] ?? null;
        $data = loadData();
        $newId = (string)(maxId($data['tree']) + 1);
        $newPerson = [
            'id'       => $newId,
            'name'     => $input['name'] ?? 'New Person',
            'title'    => $input['title'] ?? '',
            'email'       => '',
            'mobile'      => '',
            'phone'       => '',
            'birthday'    => '',
            'anniversary' => '',
            'image'       => null,
            'children' => [],
            'duties'   => []
        ];
        if ($parentId) {
            $added = addChild($data['tree'], $parentId, $newPerson);
            if (!$added) {
                http_response_code(404);
                echo json_encode(['error'=>'Parent not found']);
                exit;
            }
        } else {
            $data['tree'][] = $newPerson;
        }
        saveData($data);
        echo json_encode(['ok'=>true, 'id'=>$newId]);
        exit;
    }

    // ── Delete person ──
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'No ID']); exit; }
        $data = loadData();
        $data['tree'] = removeNode($data['tree'], $id);
        saveData($data);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Move person (change parent) ──
    if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $newParentId = $input['newParentId'] ?? null; // null = root
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'No ID']); exit; }
        $data = loadData();
        // Extract node
        $node = extractNode($data['tree'], $id);
        if (!$node) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        // Insert at new parent
        if ($newParentId) {
            $added = addChild($data['tree'], $newParentId, $node);
            if (!$added) { http_response_code(404); echo json_encode(['error'=>'Parent not found']); exit; }
        } else {
            $data['tree'][] = $node;
        }
        saveData($data);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Reorder (insert before/after a sibling) ──
    if ($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $targetId = $input['targetId'] ?? null;
        $position = $input['position'] ?? 'before'; // 'before' or 'after'
        if (!$id || !$targetId) { http_response_code(400); echo json_encode(['error'=>'Missing id or targetId']); exit; }
        if ($id === $targetId) { echo json_encode(['ok'=>true]); exit; }
        $data = loadData();
        $node = extractNode($data['tree'], $id);
        if (!$node) { http_response_code(404); echo json_encode(['error'=>'Node not found']); exit; }
        $inserted = insertNear($data['tree'], $targetId, $node, $position);
        if (!$inserted) { http_response_code(404); echo json_encode(['error'=>'Target not found']); exit; }
        saveData($data);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Upload photo ──
    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['photo'])) {
            http_response_code(400);
            echo json_encode(['error'=>'No file uploaded']);
            exit;
        }
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error'=>'Upload error: '.$file['error']]);
            exit;
        }
        if ($file['size'] > MAX_UPLOAD) {
            http_response_code(400);
            echo json_encode(['error'=>'File too large (max 5MB)']);
            exit;
        }
        $allowed = ['image/jpeg','image/png','image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid file type. Allowed: jpg, png, webp']);
            exit;
        }
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
        $filename = uniqid('photo_', true) . '.' . $ext;
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename);
        $url = 'data/uploads/' . $filename;
        echo json_encode(['ok'=>true, 'url'=>$url]);
        exit;
    }

    // ── Publish ──
    if ($action === 'publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = publish();
        if ($result === true) {
            echo json_encode(['ok'=>true]);
        } else {
            http_response_code(500);
            echo json_encode(['error'=>$result]);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['error'=>'Unknown API action']);
    exit;
}

// ─── Login handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $valid = false;
    if (defined('ADMIN_HASH')) {
        $valid = password_verify($_POST['password'], ADMIN_HASH);
    } elseif (defined('ADMIN_PASSWORD')) {
        $valid = ($_POST['password'] === ADMIN_PASSWORD);
    }
    if ($valid) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_last_activity'] = time();
    } else {
        $loginError = 'Invalid password.';
    }
}
if (isset($_GET['logout']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ./');
    exit;
}

// ─── Data helpers ────────────────────────────────────────────────────────────
function loadData(): array {
    if (!file_exists(DATA_FILE)) return ['tree'=>[]];
    $json = file_get_contents(DATA_FILE);
    return json_decode($json, true) ?: ['tree'=>[]];
}

function saveData(array $data): void {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function updateNode(array &$nodes, string $id, array $input): bool {
    foreach ($nodes as &$n) {
        if ($n['id'] === $id) {
            $n['name']   = $input['name']   ?? $n['name'];
            $n['title']  = $input['title']  ?? $n['title'];
            $n['email']  = $input['email']  ?? $n['email'];
            $n['mobile']      = $input['mobile'] ?? $n['mobile'];
            $n['phone']       = $input['phone']  ?? $n['phone'];
            $n['birthday']    = $input['birthday'] ?? ($n['birthday'] ?? '');
            $n['anniversary'] = $input['anniversary'] ?? ($n['anniversary'] ?? '');
            if (array_key_exists('image', $input)) $n['image'] = $input['image'];
            if (array_key_exists('duties', $input)) $n['duties'] = $input['duties'];
            return true;
        }
        if (!empty($n['children']) && updateNode($n['children'], $id, $input)) return true;
    }
    return false;
}

function addChild(array &$nodes, string $parentId, array $child): bool {
    foreach ($nodes as &$n) {
        if ($n['id'] === $parentId) {
            $n['children'][] = $child;
            return true;
        }
        if (!empty($n['children']) && addChild($n['children'], $parentId, $child)) return true;
    }
    return false;
}

function removeNode(array $nodes, string $id): array {
    $result = [];
    foreach ($nodes as $n) {
        if ($n['id'] === $id) continue; // skip this node (and its children)
        if (!empty($n['children'])) {
            $n['children'] = removeNode($n['children'], $id);
        }
        $result[] = $n;
    }
    return $result;
}

function extractNode(array &$nodes, string $id): ?array {
    for ($i = 0; $i < count($nodes); $i++) {
        if ($nodes[$i]['id'] === $id) {
            $node = $nodes[$i];
            array_splice($nodes, $i, 1);
            return $node;
        }
        if (!empty($nodes[$i]['children'])) {
            $found = extractNode($nodes[$i]['children'], $id);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function insertNear(array &$nodes, string $targetId, array $node, string $position): bool {
    for ($i = 0; $i < count($nodes); $i++) {
        if ($nodes[$i]['id'] === $targetId) {
            $pos = $position === 'after' ? $i + 1 : $i;
            array_splice($nodes, $pos, 0, [$node]);
            return true;
        }
        if (!empty($nodes[$i]['children'])) {
            if (insertNear($nodes[$i]['children'], $targetId, $node, $position)) return true;
        }
    }
    return false;
}

function maxId(array $nodes): int {
    $max = 0;
    foreach ($nodes as $n) {
        $max = max($max, (int)$n['id']);
        if (!empty($n['children'])) $max = max($max, maxId($n['children']));
    }
    return $max;
}

// ─── Publish: generate index.html from template + data ───────────────────────
function publish() {
    if (!file_exists(TEMPLATE_FILE)) return 'template.html not found';
    if (!file_exists(DATA_FILE))     return 'org.json not found';

    $data = loadData();
    $template = file_get_contents(TEMPLATE_FILE);

    // Build D array (tree without duties)
    $tree = stripDuties($data['tree']);
    $dJson = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Build DUTIES object keyed by id
    $duties = [];
    collectDuties($data['tree'], $duties);
    $dutiesJson = json_encode((object)$duties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = str_replace('{{DATA}}', $dJson, $template);
    $html = str_replace('{{DUTIES}}', $dutiesJson, $html);

    $written = file_put_contents(OUTPUT_FILE, $html, LOCK_EX);
    return $written !== false ? true : 'Failed to write index.html';
}

function stripDuties(array $nodes): array {
    return array_map(function($n) {
        unset($n['duties']);
        if (!empty($n['children'])) $n['children'] = stripDuties($n['children']);
        return $n;
    }, $nodes);
}

function collectDuties(array $nodes, array &$duties): void {
    foreach ($nodes as $n) {
        if (!empty($n['duties'])) {
            $duties[$n['id']] = array_map(function($d) {
                return ['h'=>$d['h'], 'd'=>$d['d']];
            }, $n['duties']);
        }
        if (!empty($n['children'])) collectDuties($n['children'], $duties);
    }
}

// ─── Render page ─────────────────────────────────────────────────────────────
$loggedIn = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dream Greener — Admin</title>
<style>
:root {
  --green-900:#1a3a1a;--green-800:#2d5a2d;--green-700:#3a7a3a;
  --green-600:#4a9a4a;--green-500:#5cb85c;--green-400:#7dc87d;
  --green-300:#a3d9a3;--green-200:#c8e8c8;--green-100:#e8f5e8;
  --green-50:#f4faf4;--cream:#fdfcf9;
  --shadow:rgba(26,58,26,.1);--text:#2a2a2a;--text-sec:#6a6a6a;
  --danger:#dc3545;--danger-bg:#fff0f0;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--cream);color:var(--text);height:100vh;overflow:hidden}

/* ── Top bar ── */
.topbar{background:linear-gradient(135deg,var(--green-900),var(--green-800));padding:12px 20px;display:flex;align-items:center;justify-content:space-between;color:white;box-shadow:0 2px 12px var(--shadow)}
.topbar h1{font-size:18px;font-weight:700}
.topbar-actions{display:flex;gap:8px;align-items:center}
.topbar .btn{padding:7px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:white;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.topbar .btn:hover{background:rgba(255,255,255,.25)}

/* ── Layout ── */
.layout{display:flex;height:calc(100vh - 52px)}
.sidebar{width:300px;min-width:260px;border-right:1.5px solid var(--green-200);background:white;overflow-y:auto;display:flex;flex-direction:column}
.sidebar-header{padding:12px 16px;border-bottom:1px solid var(--green-100);display:flex;align-items:center;justify-content:space-between}
.sidebar-header h2{font-size:14px;color:var(--green-800)}
.sidebar-header .btn-sm{padding:4px 12px;border-radius:6px;border:1px solid var(--green-200);background:var(--green-50);color:var(--green-800);font-size:12px;font-weight:600;cursor:pointer;transition:all .2s}
.sidebar-header .btn-sm:hover{background:var(--green-100)}
.tree-list{flex:1;overflow-y:auto;padding:8px 0}
.tree-list ul{list-style:none;padding-left:20px}
.tree-list>ul{padding-left:8px}
.tree-node{padding:5px 10px;margin:1px 4px;border-radius:6px;cursor:pointer;font-size:13px;transition:all .15s;display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tree-node:hover{background:var(--green-50)}
.tree-node.drag-over-middle{outline:2px dashed var(--green-500);background:var(--green-50)}
.tree-node.drag-over-top{box-shadow:0 -2px 0 0 var(--green-500)}
.tree-node.drag-over-bottom{box-shadow:0 2px 0 0 var(--green-500)}
.tree-node.active{background:var(--green-100);font-weight:600;color:var(--green-800)}
.tree-node .node-icon{width:20px;height:20px;border-radius:50%;background:var(--green-100);display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:var(--green-700);flex-shrink:0}
.tree-node .node-name{overflow:hidden;text-overflow:ellipsis}
.tree-toggle{cursor:pointer;user-select:none;font-size:10px;color:var(--text-sec);width:14px;display:inline-block;text-align:center;flex-shrink:0}

/* ── Main panel ── */
.main-panel{flex:1;overflow-y:auto;padding:24px 32px}
.main-panel.empty-state{display:flex;align-items:center;justify-content:center;color:var(--text-sec);font-size:15px}

.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--text-sec);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="date"],
.form-group select{width:100%;padding:9px 12px;border:1.5px solid var(--green-200);border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .2s;background:white}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--green-500)}
.form-group textarea{width:100%;padding:9px 12px;border:1.5px solid var(--green-200);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;min-height:60px;transition:border-color .2s}
.form-group textarea:focus{outline:none;border-color:var(--green-500)}

.photo-section{display:flex;align-items:center;gap:16px;margin-bottom:16px}
.photo-preview{width:80px;height:80px;border-radius:12px;background:var(--green-100);overflow:hidden;display:flex;align-items:center;justify-content:center;border:2px solid var(--green-200);flex-shrink:0}
.photo-preview img{width:100%;height:100%;object-fit:cover}
.photo-preview span{font-size:24px;font-weight:700;color:var(--green-600)}
.photo-actions{display:flex;flex-direction:column;gap:6px}
.photo-actions .btn-sm{padding:6px 14px;border-radius:6px;border:1px solid var(--green-200);background:white;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;color:var(--text)}
.photo-actions .btn-sm:hover{background:var(--green-50);border-color:var(--green-400)}

.duties-section{border-top:1.5px solid var(--green-100);padding-top:16px;margin-top:20px}
.duties-title{font-size:13px;font-weight:700;color:var(--green-700);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.duty-item{background:var(--green-50);border:1px solid var(--green-200);border-radius:10px;padding:12px;margin-bottom:8px;position:relative}
.duty-item .duty-heading{width:100%;padding:7px 10px;border:1px solid var(--green-200);border-radius:6px;font-size:13px;font-weight:600;font-family:inherit;margin-bottom:6px;background:white}
.duty-item .duty-desc{width:100%;padding:7px 10px;border:1px solid var(--green-200);border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;min-height:50px;background:white}
.duty-item .duty-controls{display:flex;gap:4px;position:absolute;top:8px;right:8px}
.duty-item .duty-btn{width:24px;height:24px;border-radius:4px;border:1px solid var(--green-200);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text-sec);transition:all .2s}
.duty-item .duty-btn:hover{background:var(--green-100);color:var(--text)}
.duty-item .duty-btn.remove:hover{background:var(--danger-bg);color:var(--danger);border-color:var(--danger)}
.btn-add-duty{padding:8px 16px;border-radius:8px;border:1.5px dashed var(--green-300);background:transparent;color:var(--green-700);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;width:100%}
.btn-add-duty:hover{background:var(--green-50);border-color:var(--green-500)}

.form-actions{display:flex;gap:10px;margin-top:24px;padding-top:16px;border-top:1.5px solid var(--green-100)}
.btn-save{padding:10px 28px;border-radius:8px;border:none;background:var(--green-600);color:white;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s}
.btn-save:hover{background:var(--green-500)}
.btn-delete{padding:10px 20px;border-radius:8px;border:1px solid var(--danger);background:white;color:var(--danger);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s}
.btn-delete:hover{background:var(--danger-bg)}

/* ── Login ── */
.login-wrap{display:flex;align-items:center;justify-content:center;height:100vh;background:linear-gradient(135deg,var(--green-900),var(--green-800))}
.login-card{background:white;border-radius:16px;padding:40px;width:360px;box-shadow:0 12px 40px rgba(0,0,0,.2);text-align:center}
.login-card h1{font-size:22px;color:var(--green-800);margin-bottom:4px}
.login-card p{font-size:13px;color:var(--text-sec);margin-bottom:24px}
.login-card input{width:100%;padding:11px 14px;border:1.5px solid var(--green-200);border-radius:8px;font-size:15px;margin-bottom:12px;text-align:center}
.login-card input:focus{outline:none;border-color:var(--green-500)}
.login-card button{width:100%;padding:11px;border:none;border-radius:8px;background:var(--green-600);color:white;font-size:15px;font-weight:600;cursor:pointer;transition:all .2s}
.login-card button:hover{background:var(--green-500)}
.login-error{color:var(--danger);font-size:13px;margin-bottom:12px}

/* ── Toast ── */
.toast{position:fixed;bottom:20px;right:20px;padding:12px 20px;border-radius:10px;color:white;font-size:14px;font-weight:600;z-index:999;transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green-600)}
.toast.error{background:var(--danger)}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ─── Login Screen ─── -->
<div class="login-wrap">
  <div class="login-card">
    <h1>Dream Greener</h1>
    <p>Org Chart Admin</p>
    <?php if (!empty($loginError)): ?>
      <div class="login-error"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="password" name="password" placeholder="Enter admin password" autofocus required>
      <button type="submit">Sign In</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ─── Admin Interface ─── -->
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px">
    <img src="../dg-leaf.svg" alt="DG" style="width:36px;height:36px;border-radius:50%">
    <h1>DG Org Chart Admin</h1>
  </div>
  <div class="topbar-actions">
    <a href="?logout" class="btn">Logout</a>
  </div>
</div>

<div class="layout">
  <div class="sidebar">
    <div class="sidebar-header">
      <h2>Organization</h2>
      <button class="btn-sm" onclick="addPerson()">+ Add Person</button>
    </div>
    <div class="tree-list" id="treeList"></div>
  </div>
  <div class="main-panel empty-state" id="mainPanel">
    <span>Select a person from the sidebar to edit</span>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = '<?= csrfToken() ?>';
let orgData = null;
let selectedId = null;
let flatMap = {};

// ── Init ──
async function init() {
  const res = await fetch('?api=load');
  orgData = await res.json();
  buildFlatMap();
  renderTree();
}

function buildFlatMap() {
  flatMap = {};
  function walk(nodes, parent) {
    nodes.forEach(n => {
      flatMap[n.id] = { node: n, parent: parent };
      if (n.children) walk(n.children, n);
    });
  }
  walk(orgData.tree, null);
}

// ── Sidebar tree ──
function renderTree() {
  const el = document.getElementById('treeList');
  el.innerHTML = '<ul>' + renderNodes(orgData.tree) + '</ul>';
  initDragDrop();
}

function getDragZone(e, node) {
  const rect = node.getBoundingClientRect();
  const y = e.clientY - rect.top;
  const pct = y / rect.height;
  if (pct < 0.25) return 'top';
  if (pct > 0.75) return 'bottom';
  return 'middle';
}

function clearDragClasses(node) {
  node.classList.remove('drag-over-top', 'drag-over-middle', 'drag-over-bottom');
}

function initDragDrop() {
  document.querySelectorAll('.tree-node[draggable]').forEach(node => {
    node.addEventListener('dragstart', e => {
      e.dataTransfer.setData('text/plain', node.dataset.id);
      e.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      clearDragClasses(node);
      node.classList.add('drag-over-' + getDragZone(e, node));
    });
    node.addEventListener('dragleave', () => {
      clearDragClasses(node);
    });
    node.addEventListener('drop', async e => {
      e.preventDefault();
      const zone = getDragZone(e, node);
      clearDragClasses(node);
      const draggedId = e.dataTransfer.getData('text/plain');
      const targetId = node.dataset.id;
      if (draggedId === targetId) return;

      let apiUrl, body;
      if (zone === 'middle') {
        // Reparent: make child of target
        apiUrl = '?api=move';
        body = { id: draggedId, newParentId: targetId };
      } else {
        // Reorder: insert before/after target as sibling
        apiUrl = '?api=reorder';
        body = { id: draggedId, targetId: targetId, position: zone === 'top' ? 'before' : 'after' };
      }

      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(body)
      });
      const data = await res.json();
      if (!data.ok) { toast(data.error || 'Move failed', 'error'); return; }
      await fetch('?api=publish', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
      toast(zone === 'middle' ? 'Moved under & published' : 'Reordered & published', 'success');
      await reload();
    });
  });
}

function renderNodes(nodes) {
  let h = '';
  nodes.forEach(n => {
    const hasKids = n.children && n.children.length > 0;
    const active = n.id === selectedId ? ' active' : '';
    const initials = getInitials(n.name);
    h += '<li>';
    h += '<div class="tree-node' + active + '" draggable="true" data-id="' + n.id + '" onclick="selectPerson(\'' + n.id + '\')" title="' + escHtml(n.name) + '">';
    if (hasKids) {
      h += '<span class="tree-toggle" onclick="event.stopPropagation();toggleBranch(this)">&#9660;</span>';
    } else {
      h += '<span class="tree-toggle"></span>';
    }
    h += '<span class="node-icon">' + escHtml(initials) + '</span>';
    h += '<span class="node-name">' + escHtml(n.name) + '</span>';
    h += '</div>';
    if (hasKids) {
      h += '<ul>' + renderNodes(n.children) + '</ul>';
    }
    h += '</li>';
  });
  return h;
}

function toggleBranch(el) {
  const ul = el.closest('li').querySelector(':scope > ul');
  if (!ul) return;
  const hidden = ul.style.display === 'none';
  ul.style.display = hidden ? '' : 'none';
  el.innerHTML = hidden ? '&#9660;' : '&#9654;';
}

function getInitials(name) {
  return name.split(/[\s-]+/).filter(w => w.length > 1 && w !== 'Open').slice(0, 2).map(w => w[0]).join('') || '?';
}

function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function fmtPhoneInput(el) {
  let digits = el.value.replace(/\D/g, '');
  if (digits.length > 10 && digits[0] === '1') digits = digits.slice(1);
  if (digits.length > 10) digits = digits.slice(0, 10);
  let formatted = '';
  if (digits.length > 0) formatted = '(' + digits.slice(0, 3);
  if (digits.length >= 3) formatted += ') ';
  if (digits.length > 3) formatted += digits.slice(3, 6);
  if (digits.length >= 6) formatted += '-';
  if (digits.length > 6) formatted += digits.slice(6, 10);
  el.value = formatted;
}

// ── Select & edit person ──
function selectPerson(id) {
  selectedId = id;
  renderTree();
  renderEditForm();
}

function renderEditForm() {
  const info = flatMap[selectedId];
  if (!info) return;
  const n = info.node;
  const panel = document.getElementById('mainPanel');
  panel.classList.remove('empty-state');

  // Build parent options
  let parentOpts = '<option value="">(Root - no parent)</option>';
  function addOpts(nodes, depth) {
    nodes.forEach(p => {
      if (p.id === selectedId) return; // can't be own parent
      const indent = '&nbsp;'.repeat(depth * 4);
      const sel = (info.parent && info.parent.id === p.id) ? ' selected' : '';
      parentOpts += '<option value="' + p.id + '"' + sel + '>' + indent + escHtml(p.name) + '</option>';
      if (p.children) addOpts(p.children, depth + 1);
    });
  }
  addOpts(orgData.tree, 0);

  const imgSrc = n.image || '';
  const initials = getInitials(n.name);
  const photoPreview = imgSrc
    ? '<img src="../' + escHtml(imgSrc) + '" alt="Photo" onerror="this.parentElement.innerHTML=\'<span>' + escHtml(initials) + '</span>\'">'
    : '<span>' + escHtml(initials) + '</span>';

  let dutiesHtml = '';
  (n.duties || []).forEach(d => {
    dutiesHtml += dutyItemHtml(d.h, d.d);
  });

  panel.innerHTML = `
    <h2 style="margin-bottom:20px;font-size:18px">${escHtml(n.name)}</h2>

    <div class="photo-section">
      <div class="photo-preview" id="photoPreview">${photoPreview}</div>
      <div class="photo-actions">
        <label class="btn-sm" style="display:inline-block">
          Upload Photo
          <input type="file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="uploadPhoto(this.files[0])">
        </label>
        <input type="hidden" id="photoUrl" value="${escHtml(imgSrc)}">
        ${imgSrc ? '<button class="btn-sm" onclick="clearPhoto()" style="color:var(--danger)">Remove Photo</button>' : ''}
      </div>
    </div>

    <div class="form-group">
      <label for="fName">Name</label>
      <input type="text" id="fName" value="${escHtml(n.name)}">
    </div>
    <div class="form-group">
      <label for="fTitle">Title</label>
      <input type="text" id="fTitle" value="${escHtml(n.title || '')}">
    </div>
    <div class="form-group">
      <label for="fEmail">Email</label>
      <input type="email" id="fEmail" value="${escHtml(n.email || '')}">
    </div>
    <div style="display:flex;gap:12px">
      <div class="form-group" style="flex:1">
        <label for="fMobile">Mobile</label>
        <input type="text" id="fMobile" value="${escHtml(n.mobile || '')}" oninput="fmtPhoneInput(this)">
      </div>
      <div class="form-group" style="flex:1">
        <label for="fPhone">Office Phone</label>
        <input type="text" id="fPhone" value="${escHtml(n.phone || '')}" oninput="fmtPhoneInput(this)">
      </div>
    </div>
    <div style="display:flex;gap:12px">
      <div class="form-group" style="flex:1">
        <label for="fBirthday">Birthday</label>
        <input type="date" id="fBirthday" value="${escHtml(n.birthday || '')}">
      </div>
      <div class="form-group" style="flex:1">
        <label for="fAnniversary">Anniversary</label>
        <input type="date" id="fAnniversary" value="${escHtml(n.anniversary || '')}">
      </div>
    </div>
    <div class="form-group">
      <label for="fParent">Reports To</label>
      <select id="fParent">${parentOpts}</select>
    </div>

    <div class="duties-section">
      <div class="duties-title">Responsibilities</div>
      <div id="dutiesList">${dutiesHtml}</div>
      <button class="btn-add-duty" onclick="addDuty()">+ Add Responsibility</button>
    </div>

    <div class="form-actions">
      <button class="btn-save" onclick="savePerson()">Save Changes</button>
      <button class="btn-delete" onclick="deletePerson()">Delete Person</button>
    </div>
  `;
  // Format existing phone values
  fmtPhoneInput(document.getElementById('fMobile'));
  fmtPhoneInput(document.getElementById('fPhone'));
}

function dutyItemHtml(heading, desc) {
  return `<div class="duty-item">
    <div class="duty-controls">
      <button class="duty-btn" onclick="moveDuty(this,-1)" title="Move up">&#9650;</button>
      <button class="duty-btn" onclick="moveDuty(this,1)" title="Move down">&#9660;</button>
      <button class="duty-btn remove" onclick="removeDuty(this)" title="Remove">&times;</button>
    </div>
    <input class="duty-heading" type="text" placeholder="Heading" value="${escHtml(heading || '')}">
    <textarea class="duty-desc" placeholder="Description">${escHtml(desc || '')}</textarea>
  </div>`;
}

function addDuty() {
  const list = document.getElementById('dutiesList');
  list.insertAdjacentHTML('beforeend', dutyItemHtml('', ''));
}

function removeDuty(btn) {
  btn.closest('.duty-item').remove();
}

function moveDuty(btn, dir) {
  const item = btn.closest('.duty-item');
  const list = item.parentElement;
  if (dir === -1 && item.previousElementSibling) {
    list.insertBefore(item, item.previousElementSibling);
  } else if (dir === 1 && item.nextElementSibling) {
    list.insertBefore(item.nextElementSibling, item);
  }
}

// ── Collect duties from DOM ──
function collectDuties() {
  const items = document.querySelectorAll('#dutiesList .duty-item');
  const duties = [];
  items.forEach(el => {
    const h = el.querySelector('.duty-heading').value.trim();
    const d = el.querySelector('.duty-desc').value.trim();
    if (h || d) duties.push({ h, d });
  });
  return duties;
}

// ── Photo handling ──
async function uploadPhoto(file) {
  if (!file) return;
  const fd = new FormData();
  fd.append('photo', file);
  const res = await fetch('?api=upload', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('photoUrl').value = data.url;
    document.getElementById('photoPreview').innerHTML = '<img src="../' + escHtml(data.url) + '" alt="Photo">';
    toast('Photo uploaded', 'success');
  } else {
    toast(data.error || 'Upload failed', 'error');
  }
}

function clearPhoto() {
  document.getElementById('photoUrl').value = '';
  const initials = getInitials(document.getElementById('fName').value || '?');
  document.getElementById('photoPreview').innerHTML = '<span>' + escHtml(initials) + '</span>';
}

// ── Save ──
async function savePerson() {
  const newParentId = document.getElementById('fParent').value || null;
  const currentParentId = flatMap[selectedId].parent ? flatMap[selectedId].parent.id : null;

  const payload = {
    id: selectedId,
    name: document.getElementById('fName').value,
    title: document.getElementById('fTitle').value,
    email: document.getElementById('fEmail').value,
    mobile: document.getElementById('fMobile').value,
    phone: document.getElementById('fPhone').value,
    birthday: document.getElementById('fBirthday').value,
    anniversary: document.getElementById('fAnniversary').value,
    image: document.getElementById('photoUrl').value || null,
    duties: collectDuties()
  };

  // Save person data
  const res = await fetch('?api=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  if (!data.ok) { toast(data.error || 'Save failed', 'error'); return; }

  // Move if parent changed
  if (newParentId !== currentParentId) {
    const mRes = await fetch('?api=move', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ id: selectedId, newParentId: newParentId })
    });
    const mData = await mRes.json();
    if (!mData.ok) { toast(mData.error || 'Move failed', 'error'); return; }
  }

  // Auto-publish
  const pRes = await fetch('?api=publish', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
  const pData = await pRes.json();
  if (!pData.ok) { toast('Saved but publish failed: ' + (pData.error || ''), 'error'); await reload(); return; }

  toast('Saved & published', 'success');
  await reload();
}

// ── Delete ──
async function deletePerson() {
  const info = flatMap[selectedId];
  if (!info) return;
  const childCount = info.node.children ? info.node.children.length : 0;
  let msg = 'Delete "' + info.node.name + '"?';
  if (childCount > 0) msg += '\n\nThis will also remove their ' + childCount + ' direct report(s) and all nested children.';
  if (!confirm(msg)) return;

  const res = await fetch('?api=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ id: selectedId })
  });
  const data = await res.json();
  if (data.ok) {
    selectedId = null;
    await fetch('?api=publish', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
    toast('Deleted & published', 'success');
    await reload();
    document.getElementById('mainPanel').innerHTML = '<span>Select a person from the sidebar to edit</span>';
    document.getElementById('mainPanel').classList.add('empty-state');
  } else {
    toast(data.error || 'Delete failed', 'error');
  }
}

// ── Add person ──
async function addPerson() {
  const name = prompt('Name:');
  if (!name) return;
  const title = prompt('Title (optional):', '');
  const parentId = selectedId || (orgData.tree[0] ? orgData.tree[0].id : null);

  const res = await fetch('?api=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ parentId, name, title: title || '' })
  });
  const data = await res.json();
  if (data.ok) {
    await fetch('?api=publish', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } });
    toast('Person added & published', 'success');
    await reload();
    selectPerson(data.id);
  } else {
    toast(data.error || 'Add failed', 'error');
  }
}

// ── Helpers ──
async function reload() {
  const res = await fetch('?api=load');
  orgData = await res.json();
  buildFlatMap();
  renderTree();
  if (selectedId && flatMap[selectedId]) renderEditForm();
}

function toast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + (type || 'success');
  setTimeout(() => { t.className = 'toast'; }, 3000);
}

// ── Start ──
init();
</script>

<?php endif; ?>
</body>
</html>
