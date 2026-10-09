// assets/js/esign.js
// Interactive HTML5 Signature Canvas & File Upload preview logic for eSign Module

document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('signatureCanvas');
    var clearBtn = document.getElementById('clearSignatureBtn');
    var esignForm = document.getElementById('esignForm');
    var signatureInput = document.getElementById('signature_data');
    var esignMethodInput = document.getElementById('esign_method');
    var signatureFileInput = document.getElementById('signature_file');
    var uploadPreviewBox = document.getElementById('uploadPreviewBox');
    var uploadPreviewImg = document.getElementById('uploadPreviewImg');
    var removeUploadBtn = document.getElementById('removeUploadBtn');

    var isDrawing = false;
    var hasSigned = false;
    var currentMethod = 'draw';

    // Track tab method switches
    var drawTabBtn = document.getElementById('draw-tab');
    var uploadTabBtn = document.getElementById('upload-tab');

    if (drawTabBtn) {
        drawTabBtn.addEventListener('click', function () {
            currentMethod = 'draw';
            if (esignMethodInput) esignMethodInput.value = 'draw';
        });
    }

    if (uploadTabBtn) {
        uploadTabBtn.addEventListener('click', function () {
            currentMethod = 'upload';
            if (esignMethodInput) esignMethodInput.value = 'upload';
        });
    }

    // Setup Canvas if canvas exists
    if (canvas) {
        var ctx = canvas.getContext('2d');

        ctx.strokeStyle = '#0d2238';
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var clientX = e.clientX;
            var clientY = e.clientY;

            if (e.touches && e.touches.length > 0) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            }

            return {
                x: clientX - rect.left,
                y: clientY - rect.top
            };
        }

        function startDrawing(e) {
            e.preventDefault();
            isDrawing = true;
            hasSigned = true;
            var pos = getPos(e);
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
        }

        function draw(e) {
            if (!isDrawing) return;
            e.preventDefault();
            var pos = getPos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
        }

        function stopDrawing(e) {
            if (isDrawing) {
                isDrawing = false;
                ctx.closePath();
            }
        }

        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseleave', stopDrawing);

        canvas.addEventListener('touchstart', startDrawing);
        canvas.addEventListener('touchmove', draw);
        canvas.addEventListener('touchend', stopDrawing);

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasSigned = false;
                if (signatureInput) signatureInput.value = '';
            });
        }
    }

    // Handle Upload File Preview
    if (signatureFileInput) {
        signatureFileInput.addEventListener('change', function () {
            var file = this.files[0];
            if (file) {
                var ext = file.name.split('.').pop().toLowerCase();
                if (['jpg', 'jpeg', 'png'].indexOf(ext) === -1) {
                    alert('Invalid file format. Only JPG, JPEG, and PNG images are allowed.');
                    this.value = '';
                    if (uploadPreviewBox) uploadPreviewBox.classList.add('d-none');
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    alert('File size exceeds 5MB limit. Please choose a smaller signature image.');
                    this.value = '';
                    if (uploadPreviewBox) uploadPreviewBox.classList.add('d-none');
                    return;
                }

                var reader = new FileReader();
                reader.onload = function (e) {
                    if (uploadPreviewImg) uploadPreviewImg.src = e.target.result;
                    if (uploadPreviewBox) uploadPreviewBox.classList.remove('d-none');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (removeUploadBtn) {
        removeUploadBtn.addEventListener('click', function () {
            if (signatureFileInput) signatureFileInput.value = '';
            if (uploadPreviewBox) uploadPreviewBox.classList.add('d-none');
            if (uploadPreviewImg) uploadPreviewImg.src = '';
        });
    }

    // Form Submit Handler
    if (esignForm) {
        esignForm.addEventListener('submit', function (e) {
            var activeTab = document.querySelector('#esignMethodTabs .nav-link.active');
            if (activeTab && activeTab.id === 'upload-tab') {
                currentMethod = 'upload';
            } else {
                currentMethod = 'draw';
            }

            if (currentMethod === 'draw') {
                if (!hasSigned) {
                    e.preventDefault();
                    alert('Please draw your signature on the canvas before submitting.');
                    return false;
                }

                var dataURL = canvas.toDataURL('image/png');
                if (signatureInput) {
                    signatureInput.value = dataURL;
                }
            } else if (currentMethod === 'upload') {
                var fileSelected = signatureFileInput && signatureFileInput.files.length > 0;
                var hasExistingSig = document.getElementById('has_existing_sig');

                if (!fileSelected && !hasExistingSig) {
                    e.preventDefault();
                    alert('Please select a valid signature image file (JPG/JPEG/PNG) to upload.');
                    return false;
                }
            }
        });
    }
});
