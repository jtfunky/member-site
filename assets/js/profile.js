// Profile avatar live preview
document.getElementById('avatar-input').addEventListener('change', function () {
  const file = this.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = e => { document.getElementById('avatar-preview').src = e.target.result; };
    reader.readAsDataURL(file);
  }
});
