<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$dataFile = __DIR__ . "/data.json";
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode(["users" => [], "posts" => []], JSON_PRETTY_PRINT));
}
$data = json_decode(file_get_contents($dataFile), true);

if (!isset($_COOKIE["browser_id"])) {
    $id = bin2hex(random_bytes(16));
    setcookie("browser_id", $id, time() + (86400*365), "/");
    $_COOKIE["browser_id"] = $id;
}
$browser_id = $_COOKIE["browser_id"];

if (!isset($data["users"][$browser_id])) {
    $data["users"][$browser_id] = [
        "created" => date("c"),
        "username" => null
    ];
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Handle AJAX request to change username
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["new_username"]) && isset($_POST["action"]) && $_POST["action"] === "change_username") {
    $new_username = trim($_POST["new_username"]);
    if ($new_username !== "") {
        $data["users"][$browser_id]["username"] = $new_username;
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        echo json_encode(["success"=>true,"username"=>$new_username]);
    } else {
        echo json_encode(["success"=>false]);
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["message"])) {
    $msg = trim($_POST["message"]);
    $username = $data["users"][$browser_id]["username"] ?? "Anonymous";
    $imgPaths = [];
    $replyTo = isset($_POST["reply_to"]) && $_POST["reply_to"] !== "" ? intval($_POST["reply_to"]) : null;

    if (!empty($_FILES["images"]["name"][0])) {
        $targetDir = __DIR__ . "/uploads/";
        if (!is_dir($targetDir)) mkdir($targetDir);
        foreach ($_FILES["images"]["name"] as $i => $name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ["png","jpg","jpeg"])) continue;
            $fileName = time() . "_" . rand(1000,9999) . "." . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES["images"]["tmp_name"][$i], $targetFile)) {
                $imgPaths[] = "uploads/" . $fileName;
            }
        }
    }

    if ($msg !== "" || count($imgPaths) > 0) {
        $nextId = count($data["posts"]) > 0 ? max(array_column($data["posts"], "id")) + 1 : 1;
        $data["posts"][] = [
            "id" => $nextId,
            "browser_id" => $browser_id,
            "username" => $username,
            "message" => $msg,
            "images" => $imgPaths,
            "reply_to" => $replyTo,
            "created" => date("Y-m-d H:i:s")
        ];
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    header("Location: index.php");
    exit;
}

if (isset($_GET["delete"])) {
    $id = intval($_GET["delete"]);
    $data["posts"] = array_values(array_filter($data["posts"], function($p) use ($id, $browser_id) {
        return !($p["id"] == $id && $p["browser_id"] === $browser_id);
    }));
    unset($data["users"][$browser_id]); // Remove user entirely if deleting posts (optional)
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
    header("Location: index.php");
    exit;
}

$posts = array_reverse($data["posts"]);
function findPost($posts, $id) {
    foreach ($posts as $p) {
        if ($p["id"] == $id) return $p;
    }
    return null;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Feed</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
* { font-family:"Montserrat"; }
body { margin:0; display:flex; flex-direction:column; height:100vh; }
#topbar { background:#00b894; color:white; padding:10px; display:flex; justify-content:flex-end; align-items:center; }
#topbar .material-icons { cursor:pointer; margin-left:10px; font-size:28px; }
#feed { flex:1; overflow-y:auto; padding:1em; background:#ecfff7; }
.post { background:white; padding:10px; margin-bottom:10px; border-radius:8px; }
.post img { max-width:200px; display:block; margin-top:5px; border-radius:6px; }
.delete { padding:4px 8px; border-radius:4px; font-size:0.8em; text-decoration:none; margin-top:8px; display:block; margin-bottom:3px; font-weight:600; text-align:center; background:red; color:white; width:45px; transition:0.3s; }
.delete:hover { filter:brightness(110%); text-decoration:underline; }
#message { font-size:16px; }
.reply { font-weight:600; color:white; background:dodgerblue; padding:4px 8px; border-radius:4px; font-size:0.8em; text-decoration:none; margin-top:8px; display:block; margin-bottom:3px; text-align:center; width:40px; transition:0.3s; }
.reply:hover { filter:brightness(110%); text-decoration:underline; }
#composer { display:flex; flex-direction:column; padding:10px; padding-top:4px; background:white; }
#replyBar { background:#fff8dc; padding:5px; border-left:4px solid dodgerblue; display:none; margin-bottom:8px; position:relative; }
#replyBar img { max-width:60px; max-height:40px; margin-top:4px; border-radius:4px; }
#replyBar .close { position:absolute; right:6px; top:6px; cursor:pointer; color:red; }
#previewBar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
.preview { position:relative; width:60px; height:60px; }
.preview img { width:100%; height:100%; object-fit:cover; border-radius:6px; }
.preview .remove { font-family: monospace; position:absolute; top:-6px; right:-6px; background:red; color:white; border-radius:50%; font-size:18px; cursor:pointer; width:23px; height:20px; text-align:center; font-weight:bold; }
.composer-bottom { display:flex; }
textarea { flex:1; resize:none; padding:12px; border-radius:6px; border:solid aliceblue 3px; outline:none; background:#f7feff; }
button,label { transition:0.3s; background:dodgerblue; color:white; border:solid dodgerblue 3px; border-radius:6px; margin-left:5px; padding:12px; display:flex; align-items:center; cursor:pointer; }
button:hover,label:hover { filter:brightness(110%); }
input[type="file"] { display:none; }
.material-icons { font-size:20px; }
.reply-preview { background:#eef; padding:5px; border-left:4px solid dodgerblue; margin-bottom:5px; cursor:pointer; }
.reply-preview img { max-width:100px; max-height:60px; margin-top:4px; border-radius:4px; }
</style>
</head>
<body>
<div id="topbar">
<span id="changeUsername" class="material-icons">account_circle</span>
</div>
<div id="feed">
<?php foreach ($posts as $p): ?>
<div class="post" id="post-<?= $p["id"] ?>">
<?php if (!empty($p["reply_to"])): 
$parent = findPost($data["posts"], $p["reply_to"]); 
if ($parent): ?>
<div class="reply-preview" onclick="document.getElementById('post-<?= $parent["id"] ?>').scrollIntoView({behavior:'smooth'});">
<strong><?= htmlspecialchars($parent["username"] ?? "Anonymous") ?> replied:</strong> <?= htmlspecialchars(substr($parent["message"] ?? "",0,50)) ?>
<?php if (!empty($parent["images"])): ?><br><img src="<?= htmlspecialchars($parent["images"][0]) ?>"><?php endif; ?>
</div>
<?php endif; endif; ?>
<div><strong><?= htmlspecialchars($p["username"] ?? "Anonymous") ?>:</strong> <?= nl2br(htmlspecialchars($p["message"] ?? "")) ?></div>
<?php if (!empty($p["images"])): foreach ($p["images"] as $img): ?>
<img src="<?= htmlspecialchars($img) ?>">
<?php endforeach; endif; ?>
<small><?= $p["created"] ?></small><br>
<?php if ($p["browser_id"] === $browser_id): ?>
<a class="delete" href="?delete=<?= $p["id"] ?>">Delete</a>
<?php else: ?>
<a href="#" class="reply" onclick="setReply(<?= $p["id"] ?>, `<?= htmlspecialchars(addslashes($p["message"] ?? "")) ?>`, `<?= !empty($p["images"]) ? htmlspecialchars($p["images"][0]) : '' ?>`);return false;">Reply</a>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<form id="composer" method="POST" enctype="multipart/form-data">
<div id="replyBar">
<span id="replyContent"></span>
<span class="close" onclick="clearReply()">✖</span>
</div>
<div id="previewBar"></div>
<div class="composer-bottom">
<textarea id="message" name="message" rows="2" placeholder="Type a message..."></textarea>
<label for="images"><span class="material-icons">attach_file</span></label>
<input type="file" name="images[]" id="images" accept=".png,.jpg,.jpeg" multiple>
<input type="hidden" name="reply_to" id="reply_to">
<button type="submit" class="sendBtn"><span class="material-icons">send</span></button>
</div>
</form>

<script>
const SLOW_MODE_DELAY=30;
const textarea=document.getElementById("message");
const sendBtn=document.querySelector(".sendBtn");
const fileInput=document.getElementById("images");
const previewBar=document.getElementById("previewBar");
let selectedFiles=[];

function canSendMessage(){
    const lastSent=parseInt(localStorage.getItem("lastSent")||"0");
    const now=Math.floor(Date.now()/1000);
    const diff=now-lastSent;
    if(diff<SLOW_MODE_DELAY){alert(`Slow mode is enabled! Please wait ${SLOW_MODE_DELAY-diff} seconds.`); return false;}
    localStorage.setItem("lastSent",now);
    return true;
}

function submitMessage(){
    if(!canSendMessage()) return;
    textarea.form.submit();
}

sendBtn.addEventListener("click",e=>{e.preventDefault();submitMessage();});
textarea.addEventListener("keydown",e=>{if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();submitMessage();}});

fileInput.addEventListener("change",()=>{
    for(let file of fileInput.files){if(!["image/png","image/jpeg"].includes(file.type)) continue; selectedFiles.push(file);}
    renderPreviews();
});

function renderPreviews(){
    previewBar.innerHTML="";
    selectedFiles.forEach((file,index)=>{
        const reader=new FileReader();
        reader.onload=e=>{
            const div=document.createElement("div");
            div.className="preview";
            div.innerHTML=`<img src="${e.target.result}"><span class="remove">&times;</span>`;
            div.querySelector(".remove").onclick=()=>{selectedFiles.splice(index,1);renderPreviews();}
            previewBar.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
    const dt=new DataTransfer();
    selectedFiles.forEach(f=>dt.items.add(f));
    fileInput.files=dt.files;
}

function setReply(id,msg,img){
    document.getElementById("reply_to").value=id;
    let html=`<strong>Replying:</strong> ${msg.substring(0,50)}`;
    if(img) html+=`<br><img src="${img}">`;
    document.getElementById("replyContent").innerHTML=html;
    document.getElementById("replyBar").style.display="block";
}

function clearReply(){
    document.getElementById("reply_to").value="";
    document.getElementById("replyBar").style.display="none";
}

document.getElementById("changeUsername").addEventListener("click", ()=>{
    const newUsername = prompt("Enter new username:", "<?= htmlspecialchars($data["users"][$browser_id]["username"] ?? "") ?>");
    if(newUsername && newUsername.trim() !== ""){
        fetch("", {
            method:"POST",
            headers:{"Content-Type":"application/x-www-form-urlencoded"},
            body:`action=change_username&new_username=${encodeURIComponent(newUsername)}`
        }).then(r=>r.json()).then(res=>{
            if(res.success) alert("Username changed to "+res.username); 
            location.reload();
        });
    }
});

window.setReply=setReply;
window.clearReply=clearReply;
</script>
</body>
</html>
