<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!function_exists('h')) {
  require_once __DIR__ . '/../includes/functions.php';
}

$staffSidebarPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$staffSidebarRole = $_SESSION['role'] ?? '';
$staffSidebarIsAdmin = $staffSidebarRole === 'admin';
$staffSidebarName = $_SESSION['full_name'] ?? 'Staff';

$staffSidebarLinks = [
  ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => '01', 'pages' => ['dashboard.php']],
  ['href' => 'bookings.php', 'label' => 'Bookings', 'icon' => '02', 'pages' => ['bookings.php', 'edit_booking.php', 'view_request.php', 'decline.php', 'cancel_no_show.php', 'view_preorder.php']],
  ['href' => 'reports.php', 'label' => 'Reports', 'icon' => '03', 'pages' => ['reports.php', 'forecast_accuracy.php']],
  ['href' => 'view_reviews.php', 'label' => 'Reviews', 'icon' => '04', 'pages' => ['view_reviews.php']],
  ['href' => 'redeem_voucher.php', 'label' => 'Vouchers', 'icon' => '05', 'pages' => ['redeem_voucher.php']],
  ['href' => 'booking_calendar.php', 'label' => 'Calendar', 'icon' => '06', 'pages' => ['booking_calendar.php']],
  ['href' => 'outlet_settings.php', 'label' => 'Outlets', 'icon' => '07', 'pages' => ['outlet_settings.php']],
  ['href' => 'activity_log.php', 'label' => 'Activity Log', 'icon' => '08', 'pages' => ['activity_log.php']],
];

if ($staffSidebarIsAdmin) {
  $staffSidebarLinks[] = ['href' => 'shop_owners.php', 'label' => 'Shop Owners', 'icon' => '09', 'pages' => ['shop_owners.php']];
  $staffSidebarLinks[] = ['href' => 'manage_faqs.php', 'label' => 'FAQ Manager', 'icon' => '10', 'pages' => ['manage_faqs.php', 'add_faq.php', 'edit_faq.php', 'delete_faq.php']];
}
?>
<style>
  .staff-sidebar {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px;
    background: var(--ok-navy, #0e2133);
    color: #fff;
    box-shadow: 12px 0 30px rgba(14, 33, 51, .16);
  }

  .staff-sidebar-brand,
  .staff-sidebar-nav a,
  .staff-sidebar-logout {
    display: flex;
    align-items: center;
  }

  .staff-sidebar-brand {
    gap: 12px;
  }

  .staff-sidebar-brand>span {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: var(--ok-gold, #b49755);
    color: var(--ok-navy, #0e2133);
    font-weight: 900;
  }

  .staff-sidebar-brand strong,
  .staff-sidebar-brand small,
  .staff-sidebar-user span,
  .staff-sidebar-nav strong {
    display: block;
  }

  .staff-sidebar-brand small,
  .staff-sidebar-user {
    color: rgba(255, 255, 255, .72);
    font-size: .86rem;
  }

  .staff-sidebar-user {
    padding: 12px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .08);
  }

  .staff-sidebar-nav {
    display: grid;
    gap: 8px;
  }

  .staff-sidebar-nav a,
  .staff-sidebar-logout {
    gap: 10px;
    padding: 11px 12px;
    border-radius: 8px;
    color: rgba(255, 255, 255, .82) !important;
    text-decoration: none !important;
    font-weight: 800;
    transition: background .18s ease, color .18s ease, transform .18s ease;
  }

  .staff-sidebar-nav a:hover,
  .staff-sidebar-nav a.active,
  .staff-sidebar-logout:hover {
    background: rgba(180, 151, 85, .22);
    color: #fff !important;
    transform: translateX(2px);
  }

  .staff-sidebar-nav a span {
    display: inline-grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .1);
    color: var(--ok-gold, #b49755);
    font-size: .78rem;
    flex: 0 0 30px;
  }

  .staff-sidebar-logout {
    margin-top: auto;
    border: 1px solid rgba(255, 255, 255, .14);
  }

  @media (min-width: 992px) {
    .staff-sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: 250px;
      z-index: 1030;
      overflow-y: auto;
    }

    .staff-sidebar~main.container {
      max-width: calc(100% - 300px);
      margin-left: 274px;
      margin-right: 24px;
    }
  }

  @media (max-width: 991.98px) {
    .staff-sidebar {
      position: sticky;
      top: 0;
      z-index: 1030;
    }

    .staff-sidebar-nav {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .staff-sidebar-logout {
      margin-top: 0;
    }
  }
</style>
<aside class="staff-sidebar no-print" aria-label="Back office menu">
  <div class="staff-sidebar-brand">
    <span>OK</span>
    <div>
      <strong>Back Office</strong>
      <small><?= h($staffSidebarIsAdmin ? 'Admin' : 'Shop Owner') ?></small>
    </div>
  </div>

  <div class="staff-sidebar-user">
    <span><?= h($staffSidebarName) ?></span>
  </div>

  <nav class="staff-sidebar-nav">
    <?php foreach ($staffSidebarLinks as $link): ?>
      <?php $isActive = in_array($staffSidebarPage, $link['pages'], true); ?>
      <a href="<?= h($link['href']) ?>" class="<?= $isActive ? 'active' : '' ?>">
        <span><?= h($link['icon']) ?></span>
        <strong><?= h($link['label']) ?></strong>
      </a>
    <?php endforeach; ?>
  </nav>

  <a class="staff-sidebar-logout" href="../logout.php">Logout</a>
</aside>
<style>
  .staff-mobile-toggle { display: none; }
  @media (max-width: 991.98px) {
    .staff-sidebar { padding: 12px 16px; gap: 12px; max-height: 100dvh; overflow-y: auto; }
    .staff-sidebar-brand { padding-right: 58px; min-height: 44px; }
    .staff-mobile-toggle {
      display: grid; place-items: center; position: absolute; right: 16px; top: 12px;
      width: 44px; height: 44px; border: 1px solid rgba(255,255,255,.4);
      border-radius: 8px; background: transparent; color: white; font-size: 24px;
    }
    .staff-sidebar.mobile-ready:not(.is-mobile-open)>:not(.staff-sidebar-brand):not(.staff-mobile-toggle) { display: none; }
    .staff-sidebar-nav { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .staff-sidebar-nav a { min-width: 0; padding: 10px 8px; font-size: .9rem; }
  }
</style>
<script>
  (() => {
    const sidebar = document.querySelector('.staff-sidebar');
    if (!sidebar) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'staff-mobile-toggle';
    button.textContent = '\u2630';
    button.setAttribute('aria-label', 'Open staff menu');
    button.setAttribute('aria-expanded', 'false');
    sidebar.id = 'staff-menu';
    button.setAttribute('aria-controls', sidebar.id);
    sidebar.append(button);
    sidebar.classList.add('mobile-ready');
    function setOpen(open) {
      sidebar.classList.toggle('is-mobile-open', open);
      button.setAttribute('aria-expanded', String(open));
      button.setAttribute('aria-label', open ? 'Close staff menu' : 'Open staff menu');
      button.textContent = open ? '\u00d7' : '\u2630';
    }
    button.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-mobile-open')));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && sidebar.classList.contains('is-mobile-open')) {
        setOpen(false);
        button.focus();
      }
    });
  })();
</script>
