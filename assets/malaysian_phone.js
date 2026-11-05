document.querySelectorAll('[data-my-mobile]').forEach(input => {
  if (input.readOnly) return;
  const pattern = new RegExp('^(?:' + input.dataset.myMobile + ')$');
  const validate = () => {
    const raw = input.value;
    const number = raw.startsWith('0') ? raw.slice(1) : raw;
    const valid = /^[0-9]+$/.test(raw) && pattern.test(number);
    input.setCustomValidity(valid ? '' : input.dataset.phoneError);
    return valid ? number : null;
  };
  input.addEventListener('beforeinput', event => {
    if (event.inputType === 'insertText' && event.data && /[^0-9]/.test(event.data)) event.preventDefault();
  });
  input.addEventListener('input', () => {
    const caret = input.selectionStart;
    const before = input.value.slice(0, caret).replace(/[^0-9]/g, '').length;
    input.value = input.value.replace(/[^0-9]/g, '');
    input.setSelectionRange(before, before);
    validate();
  });
  input.addEventListener('paste', event => {
    const text = event.clipboardData.getData('text').trim();
    if (!/^[+0-9 ()-]+$/.test(text)) { event.preventDefault(); return; }
    event.preventDefault();
    let digits = text.replace(/[ ()-]/g, '');
    if (digits.startsWith('+60')) digits = digits.slice(3);
    else if (digits.startsWith('0060')) digits = digits.slice(4);
    else if (digits.startsWith('60')) digits = digits.slice(2);
    else if (digits.startsWith('+')) return;
    if (!/^[0-9]+$/.test(digits)) return;
    input.setRangeText(digits, input.selectionStart, input.selectionEnd, 'end');
    input.value = input.value.slice(0, input.maxLength);
    validate();
  });
  input.addEventListener('blur', () => { const number = validate(); if (number) input.value = number; });
  validate();
});
