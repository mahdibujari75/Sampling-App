<form id="attForm" enctype="multipart/form-data">
  <input name="customerSlug" value="borna">
  <input name="projectCode" value="302">
  <input name="subType" value="C">
  <select name="dir">
    <option value="incoming">incoming</option>
    <option value="outgoing">outgoing</option>
  </select>
  <input name="note" placeholder="note">
  <input type="file" name="file" required>
  <button type="submit">Upload</button>
</form>

<script>
document.getElementById("attForm").addEventListener("submit", async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch("/api_files.php", { method:"POST", body: fd });
  const data = await res.json();
  alert(data.ok ? "Uploaded: " + data.file : "Error: " + data.error);
});
</script>
