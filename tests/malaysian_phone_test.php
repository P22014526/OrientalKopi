<?php
require_once __DIR__ . '/../includes/malaysian_phone.php';
$valid = ['012-345 6789'=>'+60123456789', '+60 12-345 6789'=>'+60123456789',
 '011-1234 5678'=>'+601112345678', '60123456789'=>'+60123456789',
 '0060123456789'=>'+60123456789', '(012) 345-6789'=>'+60123456789'];
foreach ($valid as $input=>$expected) {
 if (normalize_malaysian_mobile((string)$input) !== $expected) throw new RuntimeException('Valid number rejected: '.$input);
}
foreach (['','abc','+65 8123 4567','+600123456789','012345','0111234567','01234567890',
 '03-1234 5678','0123456789 ext 4','0123456789<script>','++60123456789',null,[]] as $input) {
 if (normalize_malaysian_mobile($input)!==null) throw new RuntimeException('Invalid number accepted');
}
echo "Malaysian phone validation tests passed.\n";
foreach (['123456789', '0123456789'] as $digits) {
 if (normalize_malaysian_mobile(submitted_malaysian_phone(['phone_digits'=>$digits])) !== '+60123456789') throw new RuntimeException('Digits input failed');
}
foreach (['abc123456789','+60123456789','123 456789',[]] as $digits) {
 if (submitted_malaysian_phone(['phone_digits'=>$digits]) !== '') throw new RuntimeException('Non-digit POST accepted');
}
