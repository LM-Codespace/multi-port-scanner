<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_FILES['test_file']) && $_FILES['test_file']['error'] == UPLOAD_ERR_OK) {
        echo "File uploaded successfully!";
    } else {
        echo "Error: " . $_FILES['test_file']['error'];
    }
}
?>
<form action="test_upload.php" method="POST" enctype="multipart/form-data">
    <input type="file" name="test_file" required>
    <button type="submit">Upload Test File</button>
</form>
