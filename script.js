const year = document.getElementById('year');
if (year) year.textContent = new Date().getFullYear();

const toggle = document.querySelector('.nav-toggle');
const navigation = document.getElementById('site-nav');
if (toggle && navigation) {
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!open));
    navigation.classList.toggle('open', !open);
  });
  navigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    navigation.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  }));
}

const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
  if (entry.isIntersecting) {
    entry.target.classList.add('visible');
    observer.unobserve(entry.target);
  }
}), { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach((item) => observer.observe(item));

const copyButton = document.querySelector('button.copy-email');
if (copyButton) {
  copyButton.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copyButton.dataset.email);
      copyButton.textContent = 'Email copied';
      setTimeout(() => { copyButton.textContent = 'Copy email'; }, 1800);
    } catch {
      window.location.href = `mailto:${copyButton.dataset.email}`;
    }
  });
}

document.querySelectorAll('[data-email-form]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const status = form.querySelector('.form-status');
    const serviceBoxes = [...form.querySelectorAll('input[name="Services"]')];
    const hasSelectedService = !serviceBoxes.length || serviceBoxes.some((box) => box.checked);

    if (!form.checkValidity() || !hasSelectedService) {
      form.reportValidity();
      status.textContent = hasSelectedService ? 'Please complete the required fields.' : 'Please select at least one service.';
      status.className = 'form-status error';
      return;
    }

    const data = new FormData(form);
    const lines = [];
    const grouped = new Map();
    for (const [key, value] of data.entries()) {
      if (key === 'Consent') continue;
      grouped.set(key, [...(grouped.get(key) || []), value]);
    }
    grouped.forEach((values, key) => lines.push(`${key}: ${values.join(', ')}`));

    const name = data.get('Name') || 'Website visitor';
    const subject = `${form.dataset.formType} from ${name}`;
    const body = `Hello CoderVex,\n\n${lines.join('\n')}\n\nSent from codervex.com`;
    status.textContent = 'Your email application is opening. Review the prepared message, then press Send.';
    status.className = 'form-status info';
    window.location.href = `mailto:support@codervex.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  });
});

document.querySelectorAll('.back-to-top').forEach((button) => {
  button.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
