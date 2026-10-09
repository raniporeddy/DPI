<?php
// includes/footer.php
require_once __DIR__ . '/../config/project_info.php';
$academicInfo = getAcademicDetails();
?>
    <!-- Footer -->
    <footer class="mt-auto">
        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-md-7 text-center text-md-start">
                    <h6 class="text-white fw-bold mb-1">
                        <i class="fas fa-university text-warning me-2"></i><?php echo e($academicInfo['project_title']); ?>
                    </h6>
                    <p class="small text-white-50 mb-1">
                        <strong>Student:</strong> <?php echo e($academicInfo['student_name']); ?> (Reg No: <code><?php echo e($academicInfo['register_number']); ?></code>) | 
                        <strong>Dept:</strong> <?php echo e($academicInfo['department']); ?>
                    </p>
                    <p class="small text-white-50 mb-0">
                        <strong>College:</strong> <?php echo e($academicInfo['college_name']); ?> | 
                        <strong>Guide:</strong> <?php echo e($academicInfo['guide_name']); ?>
                    </p>
                </div>
                <div class="col-md-5 text-center text-md-end">
                    <small class="text-white-50 d-block">&copy; <?php echo e($academicInfo['academic_year']); ?> Academic Final-Year Project.</small>
                    <a href="/digital-investor/about.php" class="small text-warning text-decoration-none me-2">
                        <i class="fas fa-info-circle me-1"></i> About Project & Credits
                    </a>
                    <span class="text-white-50">|</span>
                    <small class="text-white-50 ms-2"><i class="fas fa-shield-alt text-success me-1"></i> Academic Prototype</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="/digital-investor/assets/js/main.js"></script>
    <?php if (isset($extraJs)): echo $extraJs; endif; ?>
</body>
</html>
