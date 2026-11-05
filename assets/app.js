function setupChatbot() {
  const widget = document.querySelector('#chatWidget');
  const header = document.querySelector('#chatHeader');
  if (header && widget) header.addEventListener('click', () => widget.classList.toggle('collapsed'));

  const form = document.querySelector('#chatForm');
  if (!form) return;
  const input = document.querySelector('#chatInput');
  const messages = document.querySelector('#chatMessages');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const question = input.value.trim();
    if (!question) return;
    messages.innerHTML += `<div class="text-end mb-2"><span class="badge bg-dark text-wrap">${question}</span></div>`;
    input.value = '';
    const res = await fetch('api/chatbot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ question })
    });
    const data = await res.json();
    messages.innerHTML += `<div class="mb-2"><span class="badge bg-secondary text-wrap">${data.answer}</span></div>`;
    messages.scrollTop = messages.scrollHeight;
  });
}

document.addEventListener('DOMContentLoaded', setupChatbot);
