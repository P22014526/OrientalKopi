<?php
// Malaysian mobile ranges from Google's libphonenumber MY metadata (2026-10-29).
// https://github.com/google/libphonenumber/blob/master/resources/PhoneNumberMetadata.xml
const MY_MOBILE_PATTERN = '1(?:(?:1888[689]|4400|8(?:47|8[27])[0-4])[0-9]{4}|9[0-9]{7,8})|1(?:0(?:[23568][0-9]|4[0-6]|7[016-9]|9[0-8])|1(?:[1-5][0-9]{2}|6(?:0[5-9]|[1-9][0-9])|7(?:[0-4][0-9]|5[0-79]|6[02-4]|8[02-5]))|(?:[26][0-9]|[37][1-9]|4[235-9])[0-9]|5(?:31|9[0-9]{2})|8(?:1[23]|[236][0-9]|4[06]|5(?:46|[7-9])|7[016-9]|8[01]|9[0-8]))[0-9]{5}';
const MY_PHONE_ERROR = 'Enter a valid Malaysian mobile number, such as 012-345 6789 or +60 11-1234 5678.';

function submitted_malaysian_phone(array $post): string
{
    if (!array_key_exists('phone_digits', $post)) return is_string($post['phone'] ?? null) ? trim($post['phone']) : '';
    $digits = $post['phone_digits'];
    if (!is_string($digits) || !preg_match('/\A[0-9]{9,11}\z/', $digits)) return '';
    return '+60' . (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
}

function account_malaysian_mobile(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare('SELECT phone FROM users WHERE user_id=?');
    $stmt->execute([$userId]);
    return normalize_malaysian_mobile($stmt->fetchColumn());
}

function render_malaysian_phone_field(string $id, string $value, bool $readonly = false, bool $compact = false): void
{
    $canonical = normalize_malaysian_mobile($value);
    $digits = $canonical ? substr($canonical, 3) : '';
    ?>
    <div class="my-phone-field mb-3">
      <?php if ($compact): ?>
      <div class="my-phone-combined">
        <div class="my-phone-code">
          <label for="<?= h($id) ?>-country" class="visually-hidden">Country calling code</label>
          <select id="<?= h($id) ?>-country" class="my-phone-country-select" aria-describedby="<?= h($id) ?>-hint"><option value="60">MY +60</option></select>
        </div>
        <div class="my-phone-entry">
          <label for="<?= h($id) ?>"><?= $readonly ? 'Registered mobile number' : 'Mobile number' ?></label>
          <input id="<?= h($id) ?>" name="phone_digits" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="11" autocomplete="tel-national" class="my-phone-compact-number" value="<?= h($digits) ?>" placeholder="123456789" aria-describedby="<?= h($id) ?>-hint" <?= $readonly ? 'readonly' : 'required' ?> data-my-mobile="<?= h(MY_MOBILE_PATTERN) ?>" data-phone-error="Enter a valid Malaysian mobile number using digits only.">
        </div>
      </div>
      <div id="<?= h($id) ?>-hint" class="small text-muted mt-2">Malaysia only (+60). <?= $readonly ? 'Uses your account mobile number.' : 'Numbers only.' ?></div>
      <?php else: ?>
      <div class="my-phone-country"><span>Country / region</span><strong>+60 <span>Malaysia</span></strong></div>
      <label for="<?= h($id) ?>" class="form-label mt-3"><?= $readonly ? 'Registered mobile number' : 'Mobile number' ?></label>
      <input id="<?= h($id) ?>" name="phone_digits" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="11" autocomplete="tel-national" class="form-control my-phone-number" value="<?= h($digits) ?>" placeholder="123456789" aria-describedby="<?= h($id) ?>-hint"
        <?= $readonly ? 'readonly' : 'required' ?> data-my-mobile="<?= h(MY_MOBILE_PATTERN) ?>" data-phone-error="Enter a valid Malaysian mobile number using digits only.">
      <div id="<?= h($id) ?>-hint" class="small text-muted mt-2"><?= $readonly ? 'Uses the mobile number saved in your account.' : 'Malaysia +60. Example: 123456789.' ?></div>
      <?php endif; ?>
    </div>
    <?php
}

function normalize_malaysian_mobile($input): ?string
{
    if (!is_string($input)) return null;
    $input = trim($input);
    if ($input === '' || strlen($input) > 30 || !preg_match('/\A\+?[0-9 ()-]+\z/', $input)) return null;
    $digits = str_replace([' ', '(', ')', '-'], '', $input);
    if (str_starts_with($digits, '+60')) $national = substr($digits, 3);
    elseif (str_starts_with($digits, '0060')) $national = substr($digits, 4);
    elseif (str_starts_with($digits, '60')) $national = substr($digits, 2);
    elseif (str_starts_with($digits, '0')) $national = substr($digits, 1);
    else return null;
    return preg_match('/\A(?:' . MY_MOBILE_PATTERN . ')\z/', $national) ? '+60' . $national : null;
}
