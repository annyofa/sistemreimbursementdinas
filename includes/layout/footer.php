<?php
/**
 * includes/layout/footer.php
 *
 * Bagian penutup setiap halaman: tutup container, import Bootstrap 5 JS, tutup body & html.
 *
 * Harus di-include setelah konten utama halaman dan setelah navbar.php
 * (karena navbar.php membuka <div class="container-fluid py-4 px-4">).
 *
 * Requirements: 8.2
 */
?>

</div><!-- /.container-fluid — dibuka oleh navbar.php -->

<!-- Bootstrap 5.3.3 JS Bundle (termasuk Popper) — tanpa integrity agar tidak terganggu cache/versi CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>