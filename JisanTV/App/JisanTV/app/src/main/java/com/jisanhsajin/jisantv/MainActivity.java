package com.jisanhsajin.jisantv;

import android.content.Context;
import android.content.pm.ActivityInfo;
import android.media.AudioManager;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkRequest;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.RelativeLayout;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

public class MainActivity extends AppCompatActivity {

    private WebView webView;
    private LinearLayout loadingLayout;
    private FrameLayout customViewContainer;
    private RelativeLayout gestureOverlay;
    private View leftGestureZone, rightGestureZone;
    private LinearLayout brightnessContainer, volumeContainer;
    private ProgressBar brightnessSlider, volumeSlider;
    private View customView;
    private WebChromeClient.CustomViewCallback customViewCallback;
    private int originalOrientation;

    private AudioManager audioManager;
    private int maxVolume;
    private float currentBrightness = -1;
    private boolean isFullScreenMode = false;

    private final String URL = "https://jisanhsajin.gt.tc/";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        audioManager = (AudioManager) getSystemService(Context.AUDIO_SERVICE);
        maxVolume = audioManager.getStreamMaxVolume(AudioManager.STREAM_MUSIC);
        originalOrientation = getRequestedOrientation();

        // Initialize UI
        webView = findViewById(R.id.webView);
        webView.setBackgroundColor(ContextCompat.getColor(this, R.color.app_background));
        loadingLayout = findViewById(R.id.loading_layout);
        customViewContainer = findViewById(R.id.custom_view_container);
        gestureOverlay = findViewById(R.id.gesture_overlay);
        leftGestureZone = findViewById(R.id.left_gesture_zone);
        rightGestureZone = findViewById(R.id.right_gesture_zone);
        brightnessContainer = findViewById(R.id.brightness_container);
        volumeContainer = findViewById(R.id.volume_container);
        brightnessSlider = findViewById(R.id.brightness_slider);
        volumeSlider = findViewById(R.id.volume_slider);

        WebSettings webSettings = webView.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setMediaPlaybackRequiresUserGesture(false);
        webSettings.setUseWideViewPort(true);
        webSettings.setLoadWithOverviewMode(true);

        // Custom User-Agent for specific database requirements
        String customUA = "Mozilla/5.0 (Linux; Android " + Build.VERSION.RELEASE + "; " + Build.MODEL + ") " +
                "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1 " +
                getPackageName() + "/1.0 (Android-App-Mobile)";
        webSettings.setUserAgentString(customUA);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                
                // Transition from loading screen to WebView
                new Handler().postDelayed(() -> {
                    if (loadingLayout != null && loadingLayout.getVisibility() == View.VISIBLE) {
                        loadingLayout.setVisibility(View.GONE);
                        webView.setVisibility(View.VISIBLE);
                    }
                }, 500);

                // Enable video controls via JavaScript
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
                    webView.evaluateJavascript(
                            "javascript:(function() {" +
                                    "   var videos = document.getElementsByTagName('video');" +
                                    "   for(var i = 0; i < videos.length; i++) {" +
                                    "       videos[i].controls = true;" +
                                    "       videos[i].setAttribute('controls', 'controls');" +
                                    "   }" +
                                    "   document.addEventListener('dblclick', function(e) {" +
                                    "       e.preventDefault();" +
                                    "       e.stopPropagation();" +
                                    "   }, true);" +
                                    "})()", null);
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (isConnected()) {
                    view.loadUrl(url);
                } else {
                    view.loadUrl("file:///android_asset/offline.html");
                    Toast.makeText(MainActivity.this, "You are offline.", Toast.LENGTH_SHORT).show();
                }
                return true;
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onShowCustomView(View view, CustomViewCallback callback) {
                if (customView != null) {
                    callback.onCustomViewHidden();
                    return;
                }

                isFullScreenMode = true;
                setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE);
                webView.setVisibility(View.GONE);

                customViewContainer.addView(view, new FrameLayout.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT,
                        ViewGroup.LayoutParams.MATCH_PARENT
                ));
                customViewContainer.setVisibility(View.VISIBLE);

                customView = view;
                customViewCallback = callback;

                setupGestureOverlay();

                // Hide system UI (Sticky Immersive)
                getWindow().getDecorView().setSystemUiVisibility(
                        View.SYSTEM_UI_FLAG_FULLSCREEN |
                                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION |
                                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                );
            }

            @Override
            public void onHideCustomView() {
                if (customView == null) return;

                isFullScreenMode = false;
                gestureOverlay.setVisibility(View.GONE);
                setRequestedOrientation(originalOrientation);

                customViewContainer.removeAllViews();
                customViewContainer.setVisibility(View.GONE);
                webView.setVisibility(View.VISIBLE);

                if (customViewCallback != null) {
                    customViewCallback.onCustomViewHidden();
                }
                customView = null;
                getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
            }
        });

        if (isConnected()) {
            webView.loadUrl(URL);
        } else {
            webView.loadUrl("file:///android_asset/offline.html");
        }

        setupNetworkCallback();
    }

    private void setupGestureOverlay() {
        gestureOverlay.setVisibility(View.VISIBLE);
        gestureOverlay.bringToFront();

        // Left Side: Volume Control
        leftGestureZone.setOnTouchListener(new View.OnTouchListener() {
            private Handler handler = new Handler();
            private final Runnable hideVolumeRunnable = () -> volumeContainer.setVisibility(View.GONE);
            private float startY;

            @Override
            public boolean onTouch(View v, MotionEvent event) {
                switch (event.getAction()) {
                    case MotionEvent.ACTION_DOWN:
                        startY = event.getRawY();
                        handler.removeCallbacks(hideVolumeRunnable);
                        return true;
                    case MotionEvent.ACTION_MOVE:
                        float deltaY = startY - event.getRawY();
                        float percent = deltaY / v.getHeight();
                        startY = event.getRawY();
                        adjustVolume(percent);
                        updateSliders(false);
                        return true;
                    case MotionEvent.ACTION_UP:
                    case MotionEvent.ACTION_CANCEL:
                        handler.postDelayed(hideVolumeRunnable, 1000);
                        v.performClick();
                        return true;
                }
                return false;
            }
        });

        // Right Side: Brightness Control
        rightGestureZone.setOnTouchListener(new View.OnTouchListener() {
            private Handler handler = new Handler();
            private final Runnable hideBrightnessRunnable = () -> brightnessContainer.setVisibility(View.GONE);
            private float startY;

            @Override
            public boolean onTouch(View v, MotionEvent event) {
                switch (event.getAction()) {
                    case MotionEvent.ACTION_DOWN:
                        startY = event.getRawY();
                        handler.removeCallbacks(hideBrightnessRunnable);
                        return true;
                    case MotionEvent.ACTION_MOVE:
                        float deltaY = startY - event.getRawY();
                        float percent = deltaY / v.getHeight();
                        startY = event.getRawY();
                        adjustBrightness(percent);
                        updateSliders(true);
                        return true;
                    case MotionEvent.ACTION_UP:
                    case MotionEvent.ACTION_CANCEL:
                        handler.postDelayed(hideBrightnessRunnable, 1000);
                        v.performClick();
                        return true;
                }
                return false;
            }
        });
    }

    private void setupNetworkCallback() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            NetworkRequest request = new NetworkRequest.Builder().build();
            cm.registerNetworkCallback(request, new ConnectivityManager.NetworkCallback() {
                @Override
                public void onAvailable(Network network) {
                    runOnUiThread(() -> {
                        if (!URL.equals(webView.getUrl())) {
                            webView.loadUrl(URL);
                        }
                    });
                }
            });
        } catch (Exception ignored) {}
    }

    private void updateSliders(boolean isBrightness) {
        if (isBrightness) {
            brightnessContainer.setVisibility(View.VISIBLE);
            float brightnessValue = getWindow().getAttributes().screenBrightness;
            if (brightnessValue < 0) brightnessValue = 0.5f;
            brightnessSlider.setProgress(Math.round(brightnessValue * 100));
        } else {
            volumeContainer.setVisibility(View.VISIBLE);
            int volume = audioManager.getStreamVolume(AudioManager.STREAM_MUSIC);
            int volumePercent = (volume * 100) / maxVolume;
            volumeSlider.setProgress(volumePercent);
        }
    }

    private void adjustBrightness(float percent) {
        try {
            if (currentBrightness < 0) {
                currentBrightness = getWindow().getAttributes().screenBrightness;
                if (currentBrightness < 0) currentBrightness = 0.5f;
            }
            currentBrightness = Math.max(0.01f, Math.min(1.0f, currentBrightness + percent));
            WindowManager.LayoutParams layoutParams = getWindow().getAttributes();
            layoutParams.screenBrightness = currentBrightness;
            getWindow().setAttributes(layoutParams);
        } catch (Exception ignored) {}
    }

    private void adjustVolume(float percent) {
        try {
            int currentVolume = audioManager.getStreamVolume(AudioManager.STREAM_MUSIC);
            float step = percent * maxVolume * 1.5f; 
            int adjustment = Math.round(step);
            if (adjustment == 0 && Math.abs(percent) > 0.01f) {
                adjustment = percent > 0 ? 1 : -1;
            }
            int newVolume = Math.max(0, Math.min(maxVolume, currentVolume + adjustment));
            audioManager.setStreamVolume(AudioManager.STREAM_MUSIC, newVolume, 0);
        } catch (Exception ignored) {}
    }

    @Override
    public void onBackPressed() {
        if (customView != null) {
            webView.getWebChromeClient().onHideCustomView();
        } else if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    private boolean isConnected() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            return cm.getActiveNetworkInfo() != null && cm.getActiveNetworkInfo().isConnected();
        } catch (Exception e) {
            return false;
        }
    }
}