</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
<?php if (!empty($showCustomerSidebar)): ?>
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script>
    if (window.lucide) {
      window.lucide.createIcons();
      document.getElementById('customerSidebar')?.classList.add('has-lucide-icons');
    }
  </script>
<?php endif; ?>

</body>
</html>
