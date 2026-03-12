// OneSignalSDKWorker.js  (v16 import wrapper)
// Place this file at the site root: https://your-domain/OneSignalSDKWorker.js

// Add 'message' listener during initial evaluation (required by some browsers).
self.addEventListener('message', function (e) {
  // no-op during initial evaluation
});

// Import the v16 runtime
importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");
