(function () {
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

  var consent = null;
  try { consent = window.localStorage.getItem('codervex_analytics_consent'); } catch (error) {}

  window.gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: consent === 'granted' ? 'granted' : 'denied',
    wait_for_update: 500
  });
  window.gtag('js', new Date());
  window.gtag('config', 'G-TWF1HS1TTK', {
    allow_google_signals: false,
    allow_ad_personalization_signals: false
  });

  window.setAnalyticsConsent = function (choice) {
    var granted = choice === 'granted';
    try { window.localStorage.setItem('codervex_analytics_consent', granted ? 'granted' : 'denied'); } catch (error) {}
    window.gtag('consent', 'update', {
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      analytics_storage: granted ? 'granted' : 'denied'
    });
  };
})();
