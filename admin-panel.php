<?php
/**************************
 * Admin Panel (Full)
 * - Candidates CRUD
 * - Dashboard (stats)
 * - Results (live)
 **************************/
session_start();

/* Optional gate (uncomment if you already set admin login sessions)
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}
*/

$mysqli = new mysqli("localhost", "root", "", "school_election");
if ($mysqli->connect_error) {
    die("DB Connection failed: " . $mysqli->connect_error);
}

$msg = "";
$err = "";

/* ---------- Helpers ---------- */
function h($s) { return htmlspecialchars($s ?? "", ENT_QUOTES, 'UTF-8'); }

function ensureUploadDir($path) {
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

/* ---------- File upload (image) ---------- */
function handleCandidatePhotoUpload($fileFieldName = 'candidate_photo') {
    if (!isset($_FILES[$fileFieldName]) || $_FILES[$fileFieldName]['error'] !== UPLOAD_ERR_OK) {
        return [true, null]; // no file uploaded is not an error; caller can leave photo unchanged
    }

    $uploadDir = __DIR__ . "/uploads/candidates/";
    ensureUploadDir($uploadDir);

    $tmp = $_FILES[$fileFieldName]['tmp_name'];
    $name = $_FILES[$fileFieldName]['name'];

    // Allow only images
    $finfo = @getimagesize($tmp);
    if ($finfo === false) {
        return [false, "File is not a valid image."];
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) {
        return [false, "Only JPG, PNG, GIF, WEBP images are allowed."];
    }

    // Unique filename to avoid overwrites
    $newName = 'cand_' . uniqid() . '.' . $ext;
    $destAbs = $uploadDir . $newName;
    $destRel = "uploads/candidates/" . $newName;

    if (!move_uploaded_file($tmp, $destAbs)) {
        return [false, "Failed to move uploaded file."];
    }
    return [true, $destRel]; // relative path to store in DB
}

/* ---------- CREATE Candidate ---------- */
if (isset($_POST['add_candidate'])) {
    $full_name = trim($_POST['candidate_name'] ?? "");
    $class = trim($_POST['candidate_class'] ?? "");
    $position = trim($_POST['candidate_position'] ?? "");

    if ($full_name === "" || $class === "" || $position === "") {
        $err = "All fields are required.";
    } else {
        list($ok, $photoPathOrError) = handleCandidatePhotoUpload('candidate_photo');
        if (!$ok) {
            $err = $photoPathOrError;
        } else {
            $photo = $photoPathOrError; // can be null
            $stmt = $mysqli->prepare("INSERT INTO candidates (full_name, class, position, photo) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $full_name, $class, $position, $photo);
            if ($stmt->execute()) {
                $msg = "Candidate added successfully.";
            } else {
                $err = "DB error while adding candidate.";
            }
            $stmt->close();
        }
    }
}

/* ---------- UPDATE Candidate ---------- */
if (isset($_POST['update_candidate'])) {
    $id = intval($_POST['id'] ?? 0);
    $full_name = trim($_POST['candidate_name'] ?? "");
    $class = trim($_POST['candidate_class'] ?? "");
    $position = trim($_POST['candidate_position'] ?? "");

    if ($id <= 0 || $full_name === "" || $class === "" || $position === "") {
        $err = "All fields are required for update.";
    } else {
        // Check if a new photo is uploaded
        if (isset($_FILES['candidate_photo']) && $_FILES['candidate_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            list($ok, $photoPathOrError) = handleCandidatePhotoUpload('candidate_photo');
            if (!$ok) {
                $err = $photoPathOrError;
            } else {
                // Delete old photo if exists
                $res = $mysqli->prepare("SELECT photo FROM candidates WHERE id=?");
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($oldPhoto);
                $res->fetch();
                $res->close();

                if ($oldPhoto && strpos($oldPhoto, "uploads/candidates/") === 0) {
                    $oldAbs = __DIR__ . "/" . $oldPhoto;
                    if (is_file($oldAbs)) @unlink($oldAbs);
                }

                $newPhoto = $photoPathOrError;
                $stmt = $mysqli->prepare("UPDATE candidates SET full_name=?, class=?, position=?, photo=? WHERE id=?");
                $stmt->bind_param("ssssi", $full_name, $class, $position, $newPhoto, $id);
                $okUpd = $stmt->execute();
                $stmt->close();

                if ($okUpd) $msg = "Candidate updated successfully.";
                else $err = "DB error while updating candidate.";
            }
        } else {
            // No new photo => keep old
            $stmt = $mysqli->prepare("UPDATE candidates SET full_name=?, class=?, position=? WHERE id=?");
            $stmt->bind_param("sssi", $full_name, $class, $position, $id);
            $okUpd = $stmt->execute();
            $stmt->close();

            if ($okUpd) $msg = "Candidate updated successfully.";
            else $err = "DB error while updating candidate.";
        }
    }
}

/* ---------- DELETE Candidate ---------- */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id > 0) {
        // remove photo file if exists
        $res = $mysqli->prepare("SELECT photo FROM candidates WHERE id=?");
        $res->bind_param("i", $id);
        $res->execute();
        $res->bind_result($oldPhoto);
        $res->fetch();
        $res->close();

        if ($oldPhoto && strpos($oldPhoto, "uploads/candidates/") === 0) {
            $oldAbs = __DIR__ . "/" . $oldPhoto;
            if (is_file($oldAbs)) @unlink($oldAbs);
        }

        $del = $mysqli->prepare("DELETE FROM candidates WHERE id=?");
        $del->bind_param("i", $id);
        if ($del->execute()) $msg = "Candidate deleted.";
        else $err = "DB error while deleting candidate.";
        $del->close();
    }
}

/* ---------- Fetch data for UI ---------- */
$candidates = $mysqli->query("SELECT id, full_name, class, position, photo FROM candidates ORDER BY position, full_name");

$totalCandidates = 0;
$res = $mysqli->query("SELECT COUNT(*) AS c FROM candidates");
if ($row = $res->fetch_assoc()) $totalCandidates = (int)$row['c'];
$res->close();

$totalVotes = 0;
$res = $mysqli->query("SELECT COUNT(*) AS c FROM votes");
if ($row = $res->fetch_assoc()) $totalVotes = (int)$row['c'];
$res->close();

$votersByClass = $mysqli->query("SELECT class, COUNT(*) AS total FROM voters GROUP BY class ORDER BY class");
$candsByPosition = $mysqli->query("SELECT position, COUNT(*) AS total FROM candidates GROUP BY position ORDER BY position");

$results = $mysqli->query("
  SELECT c.position, c.id AS candidate_id, c.full_name, c.photo, COUNT(v.id) AS votes
  FROM candidates c
  LEFT JOIN votes v ON v.candidate_id = c.id
  GROUP BY c.id
  ORDER BY c.position, votes DESC, c.full_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin Panel - School Election</title>
<style>
  :root { --bg:#f4f6f9; --card:#fff; --text:#222; --muted:#666; --brand:#0055ff; }
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:0}
  header{background:var(--brand);color:#fff;padding:16px 24px}
  header h1{margin:0;font-size:22px}
  nav{display:flex;gap:12px;background:#e9eefc;padding:10px 16px}
  nav a{padding:8px 12px;border-radius:8px;text-decoration:none;color:#0b2a6b}
  nav a.active, nav a:hover{background:#cfdcfe}
  main{padding:16px;max-width:1100px;margin:0 auto}
  .card{background:var(--card);border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 4px 10px rgba(0,0,0,0.06)}
  .grid{display:grid;gap:12px}
  .grid-2{grid-template-columns:1fr 1fr}
  .grid-3{grid-template-columns:repeat(3,1fr)}
  table{width:100%;border-collapse:collapse}
  th,td{border:1px solid #e5e7eb;padding:8px;text-align:center}
  th{background:#f3f4f6}
  .stat{display:flex;align-items:center;gap:8px}
  .stat b{font-size:22px}
  .row{display:flex;gap:8px;flex-wrap:wrap}
  input[type=text], select, input[type=file]{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
  button, .btn{cursor:pointer;border:0;border-radius:8px;padding:8px 12px}
  .btn-primary{background:#16a34a;color:#fff}
  .btn-warn{background:#d97706;color:#fff}
  .btn-danger{background:#dc2626;color:#fff}
  .photo{width:56px;height:56px;border-radius:50%;object-fit:cover;border:1px solid #e5e7eb}
  .msg{padding:10px;border-radius:8px;margin-bottom:10px}
  .ok{background:#e7f7ed;color:#166534}
  .bad{background:#fde2e1;color:#7f1d1d}
  .hidden{display:none}
  .logout{background-color: blue;color:#ffff;}
</style>
<script>
  function showTab(id){
    document.getElementById('dashboard').classList.add('hidden');
    document.getElementById('candidates').classList.add('hidden');
    document.getElementById('results').classList.add('hidden');
    document.getElementById(id).classList.remove('hidden');

    document.getElementById('nav-dashboard').classList.remove('active');
    document.getElementById('nav-candidates').classList.remove('active');
    document.getElementById('nav-results').classList.remove('active');
    document.getElementById('nav-'+id).classList.add('active');
  }
</script>
</head>
<body>

<header>
  <h1>Kasita Seminary Election — Admin Panel</h1>
</header>

<nav>
  <a id="nav-dashboard" class="active" href="javascript:void(0)" onclick="showTab('dashboard')">Dashboard</a>
  <a id="nav-candidates" href="javascript:void(0)" onclick="showTab('candidates')">Candidates</a>
  <a id="nav-results" href="javascript:void(0)" onclick="showTab('results')">Results</a>
</nav>

<main>

  <?php if($msg): ?><div class="msg ok"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="msg bad"><?=h($err)?></div><?php endif; ?>

  <!-- DASHBOARD -->
  <section id="dashboard" class="card">
    <h2>Dashboard Overview</h2>
    <div class="grid grid-3" style="margin-top:8px">
      <div class="card">
        <div class="stat"><span>🧑‍💼</span> <div><div>Total Candidates</div><b><?=h($totalCandidates)?></b></div></div>
      </div>
      <div class="card">
        <div class="stat"><span>🗳️</span> <div><div>Total Votes Cast</div><b><?=h($totalVotes)?></b></div></div>
      </div>
      <div class="card">
        <div class="stat"><span>📚</span> <div><div>Unique Voters (who voted)</div>
          <b>
          <?php
            $uv = $mysqli->query("SELECT COUNT(DISTINCT voter_id) AS c FROM votes");
            $cuv = $uv && ($r=$uv->fetch_assoc()) ? (int)$r['c'] : 0;
            echo h($cuv);
          ?>
          </b></div></div>
      </div>
    </div>
    <div class="grid grid-2" style="margin-top:12px">
      <div class="card">
        <h3>Voters by Class</h3>
        <table>
          <tr><th>Class</th><th>Total Voters</th></tr>
          <?php while($votersByClass && $r = $votersByClass->fetch_assoc()): ?>
            <tr><td><?=h($r['class'])?></td><td><?=h($r['total'])?></td></tr>
          <?php endwhile; ?>
        </table>
      </div>

      <div class="card">
        <h3>Candidates by Position</h3>
        <table>
          <tr><th>Position</th><th>Total Candidates</th></tr>
          <?php while($candsByPosition && $r = $candsByPosition->fetch_assoc()): ?>
            <tr><td><?=h($r['position'])?></td><td><?=h($r['total'])?></td></tr>
          <?php endwhile; ?>
        </table>
      </div>
    </div>
    <!-- Logout button -->
<div style="text-align:center">
  <form action="student-logout.php" method="post">
    <button class="logout" type="submit">🚪 Logout</button>
  </form>
</div>
  <!-- Database Clear Button -->
    <form method="post" action="clear-data.php" onsubmit="return confirm('⚠️ Are you sure you want to clear all system data? This cannot be undone!');">
    <button type="submit" style="background:red; color:white; padding:10px; border:none; border-radius:6px; cursor:pointer;">
        🗑 Clear System Data
    </button>
</form>
  </section>
  <!-- CANDIDATES -->
  <section id="candidates" class="card hidden">
    <h2>Manage Candidates</h2>

    <!-- Add Candidate Form -->
    <div class="card">
      <form method="POST" enctype="multipart/form-data" class="grid grid-3">
        <div>
          <label>Full Name</label>
          <input type="text" name="candidate_name" required>
        </div>
        <div>
          <label>Class</label>
          <select name="candidate_class" required>
            <option value="">-- Select Class --</option>
            <option>Form I</option><option>Form II</option><option>Form III</option>
            <option>Form IV</option><option>Form V</option><option>Form VI</option>
          </select>
        </div>
        <div>
          <label>Position</label>
          <select name="candidate_position" required>
            <option value="">-- Select Position --</option>
            <option>Head Prefect</option>
            <option>Assistant Head Prefect</option>
            <option>Work Jumbe</option>
            <option>Assistant Work Jumbe</option>
            <option>English Chairperson</option>
            <option>Assistance English Chairperson</option>
            <option>Chief Sacristant</option>
            <option>Assistant Chief Sacristant</option>
            <option>Sports Master</option>
            <option>Assistant Sports Master</option>
            <option>Infimarian</option>
            <option>Assistant Infimarian</option>
            <option>BMH Master</option>
            <option>Assistant BMH Master</option>
            <option>Food Store Keeper</option>
            <option>Assistant Food Store Keeper</option>
            <option>Environmentalist</option>
            <option>Assistant Environmentalist</option>
            <option></option>
          </select>
        </div>
        <div style="grid-column:1 / span 2">
          <label>Photo</label>
          <input type="file" name="candidate_photo" accept="image/*">
        </div>
        <div style="display:flex;align-items:flex-end">
          <button class="btn btn-primary" type="submit" name="add_candidate">Add Candidate</button>
        </div>
      </form>
    </div>

    <!-- Candidate List -->
    <div class="card">
      <table>
        <tr>
          <th>ID</th><th>Photo</th><th>Name</th><th>Class</th><th>Position</th><th>Actions</th>
        </tr>
        <?php if ($candidates && $candidates->num_rows): ?>
          <?php while($row = $candidates->fetch_assoc()): ?>
            <tr>
              <td><?=h($row['id'])?></td>
              <td><?php if($row['photo']): ?><img class="photo" src="<?=h($row['photo'])?>"><?php else: ?>—<?php endif; ?></td>
              <td><?=h($row['full_name'])?></td>
              <td><?=h($row['class'])?></td>
              <td><?=h($row['position'])?></td>
              <td>
                <!-- Inline Edit Form -->
                <form method="POST" enctype="multipart/form-data" class="row" style="justify-content:center">
                  <input type="hidden" name="id" value="<?=h($row['id'])?>">
                  <input type="text" name="candidate_name" value="<?=h($row['full_name'])?>" required style="min-width:140px">
                  <select name="candidate_class" required>
                    <option selected><?=h($row['class'])?></option>
                    <option>Form I</option><option>Form II</option><option>Form III</option>
                    <option>Form IV</option><option>Form V</option><option>Form VI</option>
                  </select>
                  <select name="candidate_position" required>
                    <option selected><?=h($row['position'])?></option>
                    <option>Head Prefect</option>
                    <option>Assistant Head Prefect</option>
                    <option>Sports Master</option>
                    <option>Assistant Sports Master</option>
                    <option>Work Jumbe</option>
                    <option>Assistant Work Jumbe</option>
                  </select>
                  <input type="file" name="candidate_photo" accept="image/*">
                  <button class="btn btn-warn" type="submit" name="update_candidate">Update</button>
                  <a class="btn btn-danger" href="?delete=<?=h($row['id'])?>" onclick="return confirm('Delete this candidate?')">Delete</a>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="6">No candidates yet.</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </section>

  <!-- RESULTS -->
  <section id="results" class="card hidden">
    <h2>Live Results</h2>
    <table>
      <tr><th>Position</th><th>Candidate</th><th>Photo</th><th>Votes</th></tr>
      <?php
        $currentPos = null;
        if ($results && $results->num_rows) {
          while($r = $results->fetch_assoc()) {
            $pos = $r['position'];
            echo "<tr>";
            echo "<td>".h($pos)."</td>";
            echo "<td>".h($r['full_name'])."</td>";
            echo "<td>";
            if (!empty($r['photo'])) {
              echo '<img class="photo" src="'.h($r['photo']).'">';
            } else {
              echo "—";
            }
            echo "</td>";
            echo "<td>".h($r['votes'])."</td>";
            echo "</tr>";
          }
        } else {
          echo '<tr><td colspan="4">No votes yet.</td></tr>';
        }
      ?>
    </table>
  </section>

</main>

<script>
  // Show Dashboard by default
  showTab('dashboard');
</script>

</body>
</html>
