<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!function_exists('h')) {
  require_once __DIR__ . '/functions.php';
}

$customerSidebarPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$customerSidebarName = $_SESSION['full_name'] ?? 'Customer';
$customerSidebarOutlet = $_SESSION['selected_outlet_name'] ?? 'Choose an outlet';
$customerSidebarInitial = mb_strtoupper(mb_substr(trim($customerSidebarName), 0, 1));

if ($customerSidebarInitial === '') {
  $customerSidebarInitial = 'C';
}

$customerSidebarGroups = [
  [
    'label' => 'Reservations',
    'links' => [
      ['href' => 'index.php', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'fallback' => '01', 'pages' => ['index.php']],
      ['href' => 'availability.php', 'label' => 'Availability', 'icon' => 'calendar-search', 'fallback' => '02', 'pages' => ['availability.php', 'booking.php', 'confirmation.php', 'party_booking.php']],
      ['href' => 'my_bookings.php', 'label' => 'My Bookings', 'icon' => 'calendar-days', 'fallback' => '03', 'pages' => ['my_bookings.php', 'cancel_booking.php', 'edit_party_request.php', 'reschedule.php', 'reschedule_review.php', 'reschedule_confirm.php', 'preorder_menu.php']],
    ]
  ],
  [
    'label' => 'Explore',
    'links' => [
      ['href' => 'menu.php', 'label' => 'Food Menu', 'icon' => 'utensils', 'fallback' => '04', 'pages' => ['menu.php']],
      ['href' => 'review.php', 'label' => 'Reviews', 'icon' => 'star', 'fallback' => '05', 'pages' => ['review.php', 'write_review.php']],
      ['href' => 'rewards.php', 'label' => 'Rewards', 'icon' => 'gift', 'fallback' => '06', 'pages' => ['rewards.php']],
    ]
  ],
  [
    'label' => 'Account',
    'links' => [
      ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bell', 'fallback' => '07', 'pages' => ['notifications.php']],
      ['href' => 'index.php#contactSection', 'label' => 'Contact Us', 'icon' => 'phone', 'fallback' => '08', 'pages' => []],
      ['href' => 'view_profile.php', 'label' => 'My Profile', 'icon' => 'user-round', 'fallback' => '09', 'pages' => ['view_profile.php', 'edit_profile.php']],
      ['href' => 'select_outlet.php', 'label' => 'Change Outlet', 'icon' => 'map-pin', 'fallback' => '10', 'pages' => ['select_outlet.php']],
    ]
  ],
];
?>
<style>
  .customer-sidebar {
    --customer-sidebar-width: 268px;
    --customer-sidebar-compact-width: 84px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px 16px;
    background: var(--ok-navy, #0e2133);
    border-right: 1px solid rgba(180, 151, 85, .3);
    color: #fff;
    box-shadow: 12px 0 30px rgba(14, 33, 51, .16);
  }

  .customer-sidebar-header,
  .customer-sidebar-brand,
  .customer-sidebar-user,
  .customer-sidebar-outlet,
  .customer-sidebar-link,
  .customer-sidebar-logout {
    display: flex;
    align-items: center;
  }

  .customer-sidebar-header {
    justify-content: space-between;
    gap: 10px;
    min-height: 44px;
  }

  .customer-sidebar-brand {
    gap: 12px;
    min-width: 0;
    min-height: 44px;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
  }

  .customer-sidebar-brand:hover {
    color: #fff;
  }

  .customer-sidebar-brand:focus-visible {
    outline: 2px solid var(--ok-gold, #b49755);
    outline-offset: 4px;
  }

  .customer-sidebar-logo {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    border: 2px solid rgba(180, 151, 85, .88);
    box-shadow: 0 5px 14px rgba(0, 0, 0, .2);
    flex: 0 0 42px;
  }

  .customer-sidebar-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transform: scale(2.15);
    transform-origin: 50% 60%;
  }

  .customer-sidebar-brand-copy {
    min-width: 0;
  }

  .customer-sidebar-brand strong,
  .customer-sidebar-brand small,
  .customer-sidebar-user strong,
  .customer-sidebar-user small,
  .customer-sidebar-outlet strong,
  .customer-sidebar-outlet small,
  .customer-sidebar-link strong,
  .customer-sidebar-group-label {
    display: block;
  }

  .customer-sidebar-brand strong {
    white-space: nowrap;
  }

  .customer-sidebar-brand small,
  .customer-sidebar-user small,
  .customer-sidebar-outlet small {
    color: rgba(255, 255, 255, .64);
    font-size: .76rem;
  }

  .customer-sidebar-toggle {
    display: inline-grid;
    place-items: center;
    width: 38px;
    height: 38px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 8px;
    background: rgba(255, 255, 255, .08);
    color: #fff;
    font-size: 1.35rem;
    line-height: 1;
    flex: 0 0 38px;
    transition: background-color .18s ease, border-color .18s ease;
  }

  .customer-sidebar-toggle:hover,
  .customer-sidebar-toggle:focus-visible {
    border-color: rgba(180, 151, 85, .8);
    background: rgba(180, 151, 85, .2);
    outline: none;
  }

  .customer-sidebar-menu {
    min-height: 0;
  }

  .customer-sidebar-menu-inner {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-height: 100%;
  }

  .customer-sidebar-user {
    gap: 10px;
    padding: 10px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .07);
  }

  .customer-sidebar-avatar {
    display: inline-grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(180, 151, 85, .22);
    border: 1px solid rgba(180, 151, 85, .42);
    color: #fff;
    font-weight: 900;
    flex: 0 0 36px;
  }

  .customer-sidebar-user-copy,
  .customer-sidebar-outlet-copy {
    min-width: 0;
  }

  .customer-sidebar-user strong,
  .customer-sidebar-outlet strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .customer-sidebar-outlet {
    gap: 10px;
    padding: 11px 12px;
    border: 1px solid rgba(180, 151, 85, .3);
    border-radius: 8px;
    background: rgba(180, 151, 85, .1);
  }

  .customer-sidebar-outlet-status {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #4fc38a;
    box-shadow: 0 0 0 4px rgba(79, 195, 138, .12);
    flex: 0 0 9px;
  }

  .customer-sidebar-group+.customer-sidebar-group {
    margin-top: 12px;
  }

  .customer-sidebar-group-label {
    margin: 0 0 6px 11px;
    color: rgba(255, 255, 255, .44);
    font-size: .68rem;
    font-weight: 900;
    text-transform: uppercase;
  }

  .customer-sidebar-nav {
    display: grid;
    gap: 5px;
  }

  .customer-sidebar-link,
  .customer-sidebar-logout {
    position: relative;
    gap: 10px;
    min-height: 44px;
    padding: 7px 10px;
    border-radius: 8px;
    color: rgba(255, 255, 255, .78) !important;
    text-decoration: none !important;
    font-weight: 800;
    transition: background-color .18s ease, color .18s ease, transform .18s ease;
  }

  .customer-sidebar-link:hover,
  .customer-sidebar-link.active,
  .customer-sidebar-logout:hover {
    background: rgba(255, 255, 255, .09);
    color: #fff !important;
    transform: translateX(2px);
  }

  .customer-sidebar-link.active {
    background: rgba(180, 151, 85, .2);
    box-shadow: inset 3px 0 0 var(--ok-gold, #b49755);
  }

  .customer-sidebar-icon {
    display: inline-grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .08);
    color: var(--ok-gold, #b49755);
    flex: 0 0 32px;
  }

  .customer-sidebar-icon b {
    font-size: .7rem;
  }

  .customer-sidebar-icon svg {
    display: none;
    width: 18px;
    height: 18px;
    stroke-width: 2;
  }

  .customer-sidebar.has-lucide-icons .customer-sidebar-icon b {
    display: none;
  }

  .customer-sidebar.has-lucide-icons .customer-sidebar-icon svg {
    display: block;
  }

  .customer-sidebar-logout {
    margin-top: auto;
    border: 1px solid rgba(255, 255, 255, .12);
  }

  @media (min-width: 992px) {
    .customer-sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: var(--customer-sidebar-width);
      z-index: 1030;
      overflow-y: auto;
      transition: width .22s ease, padding .22s ease;
    }

    .customer-sidebar-menu {
      display: block !important;
      flex: 1;
    }

    .customer-sidebar~main.container {
      width: auto;
      max-width: calc(100% - 318px);
      margin-left: 292px;
      margin-right: 26px;
      transition: margin-left .22s ease, max-width .22s ease;
    }

    .customer-sidebar.is-collapsed {
      width: var(--customer-sidebar-compact-width);
      padding-inline: 12px;
    }

    .customer-sidebar.is-collapsed~main.container {
      max-width: calc(100% - 134px);
      margin-left: 108px;
    }

    .customer-sidebar.is-collapsed .customer-sidebar-header {
      flex-direction: column;
    }

    .customer-sidebar.is-collapsed .customer-sidebar-brand-copy,
    .customer-sidebar.is-collapsed .customer-sidebar-user-copy,
    .customer-sidebar.is-collapsed .customer-sidebar-outlet,
    .customer-sidebar.is-collapsed .customer-sidebar-group-label,
    .customer-sidebar.is-collapsed .customer-sidebar-link strong,
    .customer-sidebar.is-collapsed .customer-sidebar-logout strong {
      display: none;
    }

    .customer-sidebar.is-collapsed .customer-sidebar-user,
    .customer-sidebar.is-collapsed .customer-sidebar-link,
    .customer-sidebar.is-collapsed .customer-sidebar-logout {
      justify-content: center;
      padding-inline: 8px;
    }

    .customer-sidebar.is-collapsed .customer-sidebar-link.active {
      box-shadow: inset 0 -3px 0 var(--ok-gold, #b49755);
    }

    .customer-sidebar.is-collapsed .customer-sidebar-link:hover,
    .customer-sidebar.is-collapsed .customer-sidebar-logout:hover {
      transform: none;
    }
  }

  @media (max-width: 991.98px) {
    .customer-sidebar {
      position: sticky;
      top: 0;
      z-index: 1030;
      padding: 11px 16px;
    }

    .customer-sidebar-menu {
      display: none;
      max-height: calc(100vh - 76px);
      max-height: calc(100dvh - 76px);
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    .customer-sidebar.is-mobile-open .customer-sidebar-menu {
      display: block;
    }

    .customer-sidebar-menu-inner {
      padding-top: 2px;
    }

    .customer-sidebar-groups {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .customer-sidebar-group+.customer-sidebar-group {
      margin-top: 0;
    }

    .customer-sidebar-group:last-child {
      grid-column: 1 / -1;
    }

    .customer-sidebar-group:last-child .customer-sidebar-nav {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .customer-sidebar-logout {
      margin-top: 0;
    }
  }

  @media (max-width: 575.98px) {
    .customer-sidebar-groups,
    .customer-sidebar-group:last-child .customer-sidebar-nav {
      grid-template-columns: 1fr;
    }

    .customer-sidebar-group:last-child {
      grid-column: auto;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .customer-sidebar,
    .customer-sidebar~main.container,
    .customer-sidebar-link,
    .customer-sidebar-logout {
      transition: none;
    }
  }
</style>

<aside class="customer-sidebar no-print" id="customerSidebar" aria-label="Customer menu">
  <div class="customer-sidebar-header">
    <a class="customer-sidebar-brand" href="index.php" aria-label="Oriental Kopi dashboard">
      <span class="customer-sidebar-logo">
        <img src="assets/images/menu/oriental_kopi.jpg" alt="Oriental Kopi">
      </span>
      <div class="customer-sidebar-brand-copy">
        <strong>Oriental Kopi</strong>
        <small>Customer Area</small>
      </div>
    </a>

    <button
      class="customer-sidebar-toggle"
      id="customerSidebarToggle"
      type="button"
      aria-controls="customerSidebarMenu"
      aria-expanded="true"
      aria-label="Collapse sidebar"
      title="Collapse sidebar">
      <span class="customer-sidebar-toggle-symbol" aria-hidden="true">&#8249;</span>
    </button>
  </div>

  <div class="customer-sidebar-menu" id="customerSidebarMenu">
    <div class="customer-sidebar-menu-inner">
      <div class="customer-sidebar-user">
        <span class="customer-sidebar-avatar" aria-hidden="true"><?= h($customerSidebarInitial) ?></span>
        <div class="customer-sidebar-user-copy">
          <small>Signed in as</small>
          <strong><?= h($customerSidebarName) ?></strong>
        </div>
      </div>

      <div class="customer-sidebar-outlet">
        <span class="customer-sidebar-outlet-status" aria-hidden="true"></span>
        <div class="customer-sidebar-outlet-copy">
          <small>Selected Outlet</small>
          <strong><?= h($customerSidebarOutlet) ?></strong>
        </div>
      </div>

      <div class="customer-sidebar-groups">
        <?php foreach ($customerSidebarGroups as $group): ?>
          <section class="customer-sidebar-group" aria-label="<?= h($group['label']) ?>">
            <span class="customer-sidebar-group-label"><?= h($group['label']) ?></span>
            <nav class="customer-sidebar-nav">
              <?php foreach ($group['links'] as $link): ?>
                <?php $isActive = in_array($customerSidebarPage, $link['pages'], true); ?>
                <a
                  href="<?= h($link['href']) ?>"
                  class="customer-sidebar-link <?= $isActive ? 'active' : '' ?>"
                  title="<?= h($link['label']) ?>"
                  <?= $isActive ? 'aria-current="page"' : '' ?>>
                  <span class="customer-sidebar-icon" aria-hidden="true">
                    <i data-lucide="<?= h($link['icon']) ?>"></i>
                    <b><?= h($link['fallback']) ?></b>
                  </span>
                  <strong><?= h($link['label']) ?></strong>
                </a>
              <?php endforeach; ?>
            </nav>
          </section>
        <?php endforeach; ?>
      </div>

      <a class="customer-sidebar-logout" href="logout.php" title="Logout">
        <span class="customer-sidebar-icon" aria-hidden="true">
          <i data-lucide="log-out"></i>
          <b>LO</b>
        </span>
        <strong>Logout</strong>
      </a>
    </div>
  </div>
</aside>

<script>
  (() => {
    const sidebar = document.getElementById('customerSidebar');
    const toggle = document.getElementById('customerSidebarToggle');
    const symbol = toggle?.querySelector('.customer-sidebar-toggle-symbol');
    const desktopView = window.matchMedia('(min-width: 992px)');
    const storageKey = 'orientalKopiCustomerSidebarCollapsed';

    if (!sidebar || !toggle || !symbol) {
      return;
    }

    function storedDesktopState() {
      try {
        return window.localStorage.getItem(storageKey) === '1';
      } catch (error) {
        return false;
      }
    }

    function saveDesktopState(isCollapsed) {
      try {
        window.localStorage.setItem(storageKey, isCollapsed ? '1' : '0');
      } catch (error) {
        // The sidebar still works when browser storage is unavailable.
      }
    }

    function syncToggle() {
      if (desktopView.matches) {
        const isCollapsed = sidebar.classList.contains('is-collapsed');
        sidebar.classList.remove('is-mobile-open');
        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        toggle.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
        symbol.textContent = isCollapsed ? '\u203A' : '\u2039';
        return;
      }

      sidebar.classList.remove('is-collapsed');
      const isOpen = sidebar.classList.contains('is-mobile-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      toggle.setAttribute('aria-label', isOpen ? 'Close customer menu' : 'Open customer menu');
      toggle.title = isOpen ? 'Close menu' : 'Open menu';
      symbol.textContent = isOpen ? '\u00D7' : '\u2630';
    }

    function applyViewportState() {
      sidebar.classList.toggle('is-collapsed', desktopView.matches && storedDesktopState());
      sidebar.classList.remove('is-mobile-open');
      syncToggle();
    }

    toggle.addEventListener('click', () => {
      if (desktopView.matches) {
        const isCollapsed = sidebar.classList.toggle('is-collapsed');
        saveDesktopState(isCollapsed);
      } else {
        sidebar.classList.toggle('is-mobile-open');
      }

      syncToggle();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebar.classList.contains('is-mobile-open')) {
        sidebar.classList.remove('is-mobile-open');
        syncToggle();
        toggle.focus();
      }
    });

    desktopView.addEventListener?.('change', applyViewportState);
    applyViewportState();
  })();
</script>
