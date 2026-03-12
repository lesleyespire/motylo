// OneSignalSDKUpdaterWorker.js  (v16 import wrapper)
// Place this file at the site root: https://your-domain/OneSignalSDKUpdaterWorker.js

self.addEventListener('message', function (e) {
  // no-op during initial evaluation
});

importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");
