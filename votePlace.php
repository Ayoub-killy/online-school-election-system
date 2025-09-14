<?php
session_start();
include __DIR__ . "/db.php";

$db = $conn ?? $mysqli ?? null;
if (!$db) {
    die("Database connection not found.");
}

// --- helper functions ---
function col_exists($db, $table, $col) {
    $table = $db->real_escape_string($table);
    $col = $db->real_escape_string($col);
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return ($res && $res->num_rows > 0);
}
function pick_col($db, $table, $list) {
    foreach ($list as $c) if (col_exists($db, $table, $c)) return $c;
    return null;
}
function safeFetch($stmt) {
    $res = $stmt->get_result();
    return $res ? $res->fetch_assoc() : null;
}
function webPhotoPath($raw) {
    if (!$raw) return null;
    if (strpos($raw, 'uploads/') === 0) return $raw;
    if (preg_match('/^[A-Za-z]:\\\\|\\\\\\\\|\\//', $raw)) {
        return 'uploads/candidates/' . basename($raw);
    }
    if (!preg_match('#^https?://#i', $raw)) {
        return 'uploads/candidates/' . $raw;
    }
    return $raw;
}

// ---------------- validate voter session ----------------
if (!isset($_SESSION['voter_id'])) {
    header("Location: student-login.php");
    exit();
}
$sessionVoterId = $_SESSION['voter_id'];

// detect voter table columns
$voter_pk       = pick_col($db, 'voters', ['voter_id','id','voterId']);
$voter_name_col = pick_col($db, 'voters', ['full_name','fullname','name']);

if (!$voter_pk) die("Cannot find primary key column in `voters` table.");

// fetch voter row
$stmt = $db->prepare("SELECT * FROM `voters` WHERE `$voter_pk` = ? LIMIT 1");
$stmt->bind_param("i", $sessionVoterId);
$stmt->execute();
$voter = safeFetch($stmt);
$stmt->close();

if (!$voter) die("Voter not found.");

$displayName = $voter[$voter_name_col] ?? "Voter";

// candidate columns
$cand_pk    = pick_col($db, 'candidates', ['candidate_id','id','cid']);
$cand_name  = pick_col($db, 'candidates', ['full_name','fullname','name']);
$cand_class = pick_col($db, 'candidates', ['class','className']);
$cand_pos   = pick_col($db, 'candidates', ['position','post']);
$cand_photo = pick_col($db, 'candidates', ['photo','image']);

if (!$cand_pk || !$cand_name || !$cand_pos) {
    die("Candidates table missing required columns.");
}

/* ------------------ handle vote submission ------------------ */
$feedback = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['candidate_id'])) {
    $cid = intval($_POST['candidate_id']);

    // get candidate
    $q = $db->prepare("SELECT * FROM `candidates` WHERE `$cand_pk` = ? LIMIT 1");
    $q->bind_param("i", $cid);
    $q->execute();
    $candidate = safeFetch($q);
    $q->close();

    if (!$candidate) {
        $feedback = "<div style='color:red;text-align:center;'>Candidate not found.</div>";
    } else {
        $posVal = $candidate[$cand_pos];

        // check if this voter has already voted for this position
        $chk = $db->prepare("SELECT COUNT(*) AS c FROM votes WHERE voter_id = ? AND position = ?");
        $chk->bind_param("is", $sessionVoterId, $posVal);
        $chk->execute();
        $r = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($r['c'] > 0) {
            $feedback = "<div style='color:darkred;text-align:center;'>⚠️ You already voted for position: ".htmlspecialchars($posVal)."</div>";
        } else {
            // record the vote
            $ins = $db->prepare("INSERT INTO votes (voter_id, candidate_id, position) VALUES (?, ?, ?)");
            $ins->bind_param("iis", $sessionVoterId, $cid, $posVal);
            if ($ins->execute()) {
                $feedback = "<div style='color:green;text-align:center;'>✅ Vote for ".htmlspecialchars($posVal)." recorded successfully!</div>";
            } else {
                $feedback = "<div style='color:red;text-align:center;'>❌ Failed to record vote. Try again.</div>";
            }
            $ins->close();
        }
    }
}

/* ------------------ fetch candidates ------------------ */
$query = "SELECT `$cand_pk` AS cid, `$cand_name` AS cname, `$cand_class` AS cclass, `$cand_pos` AS cpos, `$cand_photo` AS cphoto 
          FROM `candidates` ORDER BY `$cand_pos`, `$cand_name`";
$candidates = $db->query($query);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Vote Now</title>
  <style>
    body{font-family:Arial;margin:18px}
    h2{color:#0b3b5b;text-align:center}
    .position-block{border:1px solid #e0e6eb;border-radius:8px;padding:16px;margin:16px 0}
    .candidate{display:inline-block;width:180px;padding:10px;margin:8px;border:1px solid #ddd;border-radius:8px;text-align:center;vertical-align:top}
    .photo{width:100px;height:100px;border-radius:50%;object-fit:cover;margin-bottom:8px}
    .btn{background:#16a34a;color:#fff;padding:8px 12px;border-radius:6px;border:none;cursor:pointer}
    .logout{background:#b91c1c;color:#fff;padding:8px 14px;border-radius:6px;border:none;cursor:pointer;margin-top:20px}
    .msg{margin:10px 0;text-align:center}
  </style>
</head>
<body>

<h2>🗳️ Welcome, <?=htmlspecialchars($displayName)?>! Cast Your Votes</h2>

<div class="msg"><?= $feedback ?></div>

<?php
$currentPos = null;
if ($candidates && $candidates->num_rows) {
    while ($r = $candidates->fetch_assoc()) {
        $pos = $r['cpos'];
        if ($pos !== $currentPos) {
            if ($currentPos !== null) echo "</div>";
            $currentPos = $pos;
            echo "<div class='position-block'><h3>Position: " . htmlspecialchars($currentPos) . "</h3>";
        }

        $rawPhoto = $r['cphoto'];
        $photoPath = webPhotoPath($rawPhoto);

        echo "<div class='candidate'>";
        if ($photoPath && file_exists(__DIR__ . '/' . $photoPath)) {
            echo "<img class='photo' src='" . htmlspecialchars($photoPath) . "' alt='photo'>";
        } else {
            echo "<div class='photo' style='display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#666'>No<br>Photo</div>";
        }
        echo "<div style='font-weight:700;margin-top:6px'>" . htmlspecialchars($r['cname']) . "</div>";
        echo "<div style='color:#555;margin-bottom:6px'>" . htmlspecialchars($r['cclass']) . "</div>";

        echo "<form method='post'>";
        echo "<input type='hidden' name='candidate_id' value='" . intval($r['cid']) . "'>";
        echo "<button class='btn' type='submit'>Vote</button>";
        echo "</form>";

        echo "</div>";
    }
    if ($currentPos !== null) echo "</div>";
} else {
    echo "<div>No candidates found.</div>";
}
?>

<!-- Logout button -->
<div style="text-align:center">
  <form action="student-logout.php" method="post">
    <button class="logout" type="submit">🚪 Logout</button>
  </form>
</div>

</body>
</html>
