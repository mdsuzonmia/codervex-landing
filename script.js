const year = document.getElementById('year');
if (year) year.textContent = new Date().getFullYear();

const consentBanner = document.createElement('aside');
consentBanner.className = 'consent-banner';
consentBanner.setAttribute('aria-label', 'Analytics preferences');
consentBanner.innerHTML = '<div><strong>Your privacy matters</strong><p>We use Google Analytics to understand how visitors use this website. Analytics cookies are optional.</p></div><div class="consent-actions"><button type="button" data-consent="denied">Decline</button><button type="button" class="consent-accept" data-consent="granted">Accept analytics</button></div>';
document.body.appendChild(consentBanner);

const privacyButton = document.createElement('button');
privacyButton.type = 'button';
privacyButton.className = 'privacy-settings';
privacyButton.textContent = 'Privacy settings';
privacyButton.setAttribute('aria-label', 'Change analytics preferences');
document.body.appendChild(privacyButton);

let savedConsent = null;
try { savedConsent = localStorage.getItem('codervex_analytics_consent'); } catch (error) {}
if (!savedConsent) consentBanner.classList.add('show');

consentBanner.querySelectorAll('[data-consent]').forEach((button) => {
  button.addEventListener('click', () => {
    if (window.setAnalyticsConsent) window.setAnalyticsConsent(button.dataset.consent);
    consentBanner.classList.remove('show');
    privacyButton.classList.add('show');
  });
});

privacyButton.addEventListener('click', () => {
  consentBanner.classList.add('show');
  privacyButton.classList.remove('show');
});

if (savedConsent) privacyButton.classList.add('show');

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

document.querySelectorAll('[data-php-form]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = form.querySelector('.form-status');
    const submitButton = form.querySelector('[type="submit"]');
    const serviceBoxes = [...form.querySelectorAll('input[name="Services[]"]')];
    const hasSelectedService = !serviceBoxes.length || serviceBoxes.some((box) => box.checked);

    if (!form.checkValidity() || !hasSelectedService) {
      form.reportValidity();
      status.textContent = hasSelectedService ? 'Please complete the required fields.' : 'Please select at least one service.';
      status.className = 'form-status error';
      return;
    }

    const originalLabel = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.textContent = 'Sending…';
    status.textContent = 'Sending your message securely…';
    status.className = 'form-status info';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to send your message.');
      status.textContent = result.message;
      status.className = 'form-status success';
      form.reset();
    } catch (error) {
      status.textContent = error.message || 'Unable to send. Please email support@codervex.com directly.';
      status.className = 'form-status error';
    } finally {
      submitButton.disabled = false;
      submitButton.innerHTML = originalLabel;
    }
  });
});

document.querySelectorAll('.back-to-top').forEach((button) => {
  button.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
