package com.jisanhsajin.jisanrealtv;

import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.ActivityInfo;
import android.net.Uri;
import android.os.Bundle;
import android.provider.Settings;
import android.os.Handler;
import android.util.Log;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.exoplayer2.ExoPlayer;
import com.google.android.exoplayer2.MediaItem;
import com.google.android.exoplayer2.PlaybackException;
import com.google.android.exoplayer2.Player;
import com.google.android.exoplayer2.trackselection.DefaultTrackSelector;
import com.google.android.exoplayer2.ui.PlayerView;
import com.google.android.exoplayer2.upstream.DefaultDataSourceFactory;
import com.google.android.exoplayer2.util.Util;
import com.google.gson.Gson;
import com.google.gson.JsonArray;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.google.gson.reflect.TypeToken;

import java.io.IOException;
import java.lang.reflect.Type;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.TimeUnit;

import okhttp3.*;

public class MainActivity extends AppCompatActivity {

    private static final String TAG = "MainActivity";
    private static final String BASE_URL = "https://jisanrealtv-proxy.jisanhsajin.workers.dev";
    private static final long TRIPLE_CLICK_TIMEOUT = 400;
    private static final long VOLUME_BAR_HIDE_DELAY = 2000;

    private PlayerView playerView;
    private ExoPlayer player;
    private TextView channelNameText, channelNumberText;
    private LinearLayout infoOverlay;
    private LinearLayout channelListPanel;
    private RecyclerView categoryRecyclerView;
    private RecyclerView channelRecyclerView;
    private CategoryAdapter categoryAdapter;
    private ChannelAdapter channelAdapter;

    private LinearLayout volumeBarContainer;
    private ProgressBar volumeProgressBar;
    private TextView volumePercentageText;
    private Handler volumeBarHandler = new Handler();
    private Runnable hideVolumeBarRunnable;

    private FrameLayout blueErrorScreen;
    private TextView errorChannelName;

    private Handler handler = new Handler();
    private List<Channel> allChannels = new ArrayList<>();
    private List<Channel> filteredChannels = new ArrayList<>();
    private List<String> categories = new ArrayList<>();
    private Map<String, List<Channel>> channelsByCategory = new LinkedHashMap<>();
    private String selectedCategory = "All";

    private int currentChannelIndex = 0;
    private StringBuilder channelNumberBuilder = new StringBuilder();
    private boolean isChannelListVisible = false;
    private boolean isErrorScreenShowing = false;

    private int clickCount = 0;
    private long lastClickTime = 0;
    private Runnable resetClickCountRunnable = () -> {
        clickCount = 0;
    };

    private OkHttpClient client;
    private SharedPreferences prefs;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE);
        setContentView(R.layout.activity_main);

        playerView = findViewById(R.id.playerView);
        channelNameText = findViewById(R.id.channelNameText);
        channelNumberText = findViewById(R.id.channelNumberText);
        infoOverlay = findViewById(R.id.infoOverlay);
        channelListPanel = findViewById(R.id.channelListPanel);
        categoryRecyclerView = findViewById(R.id.categoryRecyclerView);
        channelRecyclerView = findViewById(R.id.channelRecyclerView);

        volumeBarContainer = findViewById(R.id.volumeBarContainer);
        volumeProgressBar = findViewById(R.id.volumeProgressBar);
        volumePercentageText = findViewById(R.id.volumePercentageText);

        blueErrorScreen = findViewById(R.id.blueErrorScreen);
        errorChannelName = findViewById(R.id.errorChannelName);

        hideVolumeBarRunnable = () -> {
            volumeBarContainer.setVisibility(View.GONE);
        };

        prefs = getSharedPreferences("TVApp", MODE_PRIVATE);

        client = new OkHttpClient.Builder()
                .connectTimeout(30, TimeUnit.SECONDS)
                .writeTimeout(30, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .build();

        setupPlayer();
        setupCategoryList();
        setupChannelList();
        loadChannels();
    }

    private void setupPlayer() {
        DefaultTrackSelector trackSelector = new DefaultTrackSelector(this);
        player = new ExoPlayer.Builder(this)
                .setTrackSelector(trackSelector)
                .build();

        playerView.setPlayer(player);
        playerView.setUseController(false);
        playerView.setKeepScreenOn(true);

        player.addListener(new Player.Listener() {
            @Override
            public void onPlayerError(PlaybackException error) {
                Log.e(TAG, "Player error: " + error.getMessage());
                runOnUiThread(() -> {
                    if (currentChannelIndex < allChannels.size()) {
                        showBlueErrorScreen(allChannels.get(currentChannelIndex).getName());
                    }
                });
            }

            @Override
            public void onPlaybackStateChanged(int playbackState) {
                if (playbackState == Player.STATE_READY) {
                    runOnUiThread(() -> {
                        if (isErrorScreenShowing) {
                            hideBlueErrorScreen();
                        }
                    });
                }
            }
        });
    }

    private void setupCategoryList() {
        categoryAdapter = new CategoryAdapter(categories, category -> {
            selectedCategory = category;
            updateChannelsForSelectedCategory();
            showChannelInfo("Category: " + category, "");
            handler.postDelayed(() -> {
                if (filteredChannels.size() > 0 && channelRecyclerView.getChildCount() > 0) {
                    channelRecyclerView.getChildAt(0).requestFocus();
                }
            }, 100);
        });

        categoryRecyclerView.setLayoutManager(new LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false));
        categoryRecyclerView.setAdapter(categoryAdapter);
        categoryRecyclerView.setFocusable(true);
        categoryRecyclerView.setFocusableInTouchMode(true);
    }

    private void setupChannelList() {
        channelAdapter = new ChannelAdapter(filteredChannels, position -> {
            playChannel(findOriginalIndex(position));
            hideChannelList();
        });

        channelRecyclerView.setLayoutManager(new GridLayoutManager(this, 6));
        channelRecyclerView.setAdapter(channelAdapter);
        channelRecyclerView.setFocusable(true);
        channelRecyclerView.setFocusableInTouchMode(true);
    }

    private void loadChannels() {
        String userId = prefs.getString("user_id", "0");
        String deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        String url = BASE_URL + "/app_channels.php?user_id=" + userId + "&device_id=" + deviceId;

        Log.d(TAG, "Loading channels from: " + url);

        Request request = new Request.Builder().url(url).build();

        client.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, IOException e) {
                Log.e(TAG, "Failed to load channels: " + e.getMessage());
                runOnUiThread(() ->
                        Toast.makeText(MainActivity.this, "Failed to load channels", Toast.LENGTH_SHORT).show()
                );
            }

            @Override
            public void onResponse(Call call, Response response) throws IOException {
                String responseData = response.body().string();
                runOnUiThread(() -> {
                    try {
                        JsonObject jsonObject = JsonParser.parseString(responseData).getAsJsonObject();
                        if (jsonObject.has("success") && !jsonObject.get("success").getAsBoolean()) {
                            String message = jsonObject.has("message") ? jsonObject.get("message").getAsString() : "Session expired";
                            if (message.contains("device") || message.contains("Unauthorized")) {
                                logoutUser(message);
                                return;
                            }
                        }

                        boolean success = jsonObject.has("success") && jsonObject.get("success").getAsBoolean();
                        if (success) {
                            JsonArray channelsArray = jsonObject.getAsJsonArray("channels");
                            Gson gson = new Gson();
                            Type channelListType = new TypeToken<List<Channel>>(){}.getType();
                            List<Channel> loadedChannels = gson.fromJson(channelsArray, channelListType);

                            if (loadedChannels != null && !loadedChannels.isEmpty()) {
                                allChannels.clear();
                                allChannels.addAll(loadedChannels);
                                organizeChannelsByCategory();
                                sortChannelsWithinCategories();
                                createSortedAllCategory();
                                updateChannelsForSelectedCategory();
                                playChannel(0);
                            }
                        } else {
                            String errorMsg = jsonObject.has("message") ? jsonObject.get("message").getAsString() : "Unknown error";
                            Toast.makeText(MainActivity.this, errorMsg, Toast.LENGTH_SHORT).show();
                        }
                    } catch (Exception e) {
                        e.printStackTrace();
                    }
                });
            }
        });
    }

    private void logoutUser(String message) {
        Toast.makeText(MainActivity.this, message, Toast.LENGTH_LONG).show();
        SharedPreferences.Editor editor = prefs.edit();
        editor.clear();
        editor.apply();
        Intent intent = new Intent(MainActivity.this, LoginActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    private void organizeChannelsByCategory() {
        channelsByCategory.clear();
        Set<String> premiumCategories = new LinkedHashSet<>();
        Set<String> freeCategories = new LinkedHashSet<>();

        for (Channel channel : allChannels) {
            String category = channel.getCategory() == null || channel.getCategory().isEmpty() ? "Others" : channel.getCategory();
            if ("premium".equals(channel.getType())) {
                premiumCategories.add(category);
            } else {
                freeCategories.add(category);
            }
        }

        for (String category : premiumCategories) channelsByCategory.put(category, new ArrayList<>());
        for (String category : freeCategories) channelsByCategory.put(category, new ArrayList<>());
        channelsByCategory.put("All", new ArrayList<>());

        for (Channel channel : allChannels) {
            String category = channel.getCategory() == null || channel.getCategory().isEmpty() ? "Others" : channel.getCategory();
            List<Channel> categoryChannels = channelsByCategory.get(category);
            if (categoryChannels != null) categoryChannels.add(channel);
        }

        categories.clear();
        categories.add("All");
        categories.addAll(premiumCategories);
        categories.addAll(freeCategories);
        categoryAdapter.notifyDataSetChanged();
    }

    private void sortChannelsWithinCategories() {
        for (Map.Entry<String, List<Channel>> entry : channelsByCategory.entrySet()) {
            if ("All".equals(entry.getKey())) continue;
            Collections.sort(entry.getValue(), (c1, c2) -> {
                String name1 = c1.getName() != null ? c1.getName() : "";
                String name2 = c2.getName() != null ? c2.getName() : "";
                return name1.compareToIgnoreCase(name2);
            });
        }
    }

    private void createSortedAllCategory() {
        List<Channel> allCategoryList = new ArrayList<>();
        for (String category : categories) {
            if (!"All".equals(category)) {
                List<Channel> categoryChannels = channelsByCategory.get(category);
                if (categoryChannels != null) allCategoryList.addAll(categoryChannels);
            }
        }
        channelsByCategory.put("All", allCategoryList);
    }

    private void updateChannelsForSelectedCategory() {
        List<Channel> sourceChannels = channelsByCategory.get(selectedCategory);
        if (sourceChannels == null) sourceChannels = allChannels;
        filteredChannels.clear();
        filteredChannels.addAll(sourceChannels);
        channelAdapter.notifyDataSetChanged();
    }

    private int findOriginalIndex(int filteredPosition) {
        if (filteredPosition < 0 || filteredPosition >= filteredChannels.size()) return 0;
        return allChannels.indexOf(filteredChannels.get(filteredPosition));
    }

    private void playChannel(int index) {
        if (allChannels.isEmpty() || index < 0 || index >= allChannels.size()) return;
        currentChannelIndex = index;
        Channel channel = allChannels.get(index);
        if (isErrorScreenShowing) hideBlueErrorScreen();

        try {
            MediaItem mediaItem = MediaItem.fromUri(Uri.parse(channel.getUrl()));
            player.setMediaItem(mediaItem);
            player.prepare();
            player.setPlayWhenReady(true);
            showChannelInfo(channel.getName(), String.valueOf(index + 1));
        } catch (Exception e) {
            showBlueErrorScreen(channel.getName());
        }
    }

    private void showChannelInfo(String name, String number) {
        channelNameText.setText(name);
        channelNumberText.setText(number);
        infoOverlay.setVisibility(View.VISIBLE);
        handler.removeCallbacks(hideOverlayRunnable);
        handler.postDelayed(hideOverlayRunnable, 3000);
    }

    private Runnable hideOverlayRunnable = () -> infoOverlay.setVisibility(View.GONE);

    private void showVolumeBar(int volumePercent) {
        volumeProgressBar.setProgress(volumePercent);
        volumePercentageText.setText(volumePercent + "%");
        volumeBarContainer.setVisibility(View.VISIBLE);
        volumeBarHandler.removeCallbacks(hideVolumeBarRunnable);
        volumeBarHandler.postDelayed(hideVolumeBarRunnable, VOLUME_BAR_HIDE_DELAY);
    }

    private void showBlueErrorScreen(String channelName) {
        blueErrorScreen.setVisibility(View.VISIBLE);
        errorChannelName.setText(channelName);
        isErrorScreenShowing = true;
    }

    private void hideBlueErrorScreen() {
        blueErrorScreen.setVisibility(View.GONE);
        isErrorScreenShowing = false;
    }

    private void showChannelList() {
        channelListPanel.setVisibility(View.VISIBLE);
        isChannelListVisible = true;
        handler.postDelayed(() -> {
            if (filteredChannels.size() > 0 && channelRecyclerView.getChildCount() > 0) {
                channelRecyclerView.getChildAt(0).requestFocus();
            }
        }, 200);
    }

    private void hideChannelList() {
        channelListPanel.setVisibility(View.GONE);
        isChannelListVisible = false;
        playerView.requestFocus();
    }

    private void toggleChannelList() {
        if (isChannelListVisible) hideChannelList(); else showChannelList();
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_VOLUME_UP) {
            player.setVolume(Math.min(1.0f, player.getVolume() + 0.1f));
            showVolumeBar((int)(player.getVolume() * 100));
            return true;
        }
        if (keyCode == KeyEvent.KEYCODE_VOLUME_DOWN) {
            player.setVolume(Math.max(0.0f, player.getVolume() - 0.1f));
            showVolumeBar((int)(player.getVolume() * 100));
            return true;
        }
        if (keyCode == KeyEvent.KEYCODE_CHANNEL_UP) {
            playChannel(currentChannelIndex < allChannels.size() - 1 ? currentChannelIndex + 1 : 0);
            return true;
        }
        if (keyCode == KeyEvent.KEYCODE_CHANNEL_DOWN) {
            playChannel(currentChannelIndex > 0 ? currentChannelIndex - 1 : allChannels.size() - 1);
            return true;
        }
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) {
            long currentTime = System.currentTimeMillis();
            if (currentTime - lastClickTime < TRIPLE_CLICK_TIMEOUT) clickCount++; else clickCount = 1;
            lastClickTime = currentTime;
            handler.removeCallbacks(resetClickCountRunnable);
            handler.postDelayed(resetClickCountRunnable, TRIPLE_CLICK_TIMEOUT);
            if (clickCount >= 3) {
                clickCount = 0;
                handler.removeCallbacks(resetClickCountRunnable);
                if (isChannelListVisible) handleTripleClick();
                return true;
            }
            return true;
        }
        if (isChannelListVisible) return handleChannelListNavigation(keyCode, event);
        if (keyCode >= KeyEvent.KEYCODE_0 && keyCode <= KeyEvent.KEYCODE_9) {
            channelNumberBuilder.append(keyCode - KeyEvent.KEYCODE_0);
            showChannelInfo("", channelNumberBuilder.toString());
            handler.removeCallbacks(channelJumpRunnable);
            handler.postDelayed(channelJumpRunnable, 1000);
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    public boolean onKeyUp(int keyCode, KeyEvent event) {
        if ((keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) && clickCount == 1) {
            toggleChannelList();
            clickCount = 0;
            handler.removeCallbacks(resetClickCountRunnable);
            return true;
        }
        return super.onKeyUp(keyCode, event);
    }

    private void handleTripleClick() {
        View currentFocus = getCurrentFocus();
        if (currentFocus != null && currentFocus.getParent() == categoryRecyclerView) {
            if (filteredChannels.size() > 0 && channelRecyclerView.getChildCount() > 0) {
                channelRecyclerView.getChildAt(0).requestFocus();
                showChannelInfo("Jump to", "Channels");
            }
        } else if (currentFocus != null && currentFocus.getParent() == channelRecyclerView) {
            if (categories.size() > 0 && categoryRecyclerView.getChildCount() > 0) {
                categoryRecyclerView.getChildAt(0).requestFocus();
                showChannelInfo("Jump to", "Categories");
            }
        }
    }

    private boolean handleChannelListNavigation(int keyCode, KeyEvent event) {
        switch (keyCode) {
            case KeyEvent.KEYCODE_DPAD_UP:
            case KeyEvent.KEYCODE_DPAD_DOWN:
            case KeyEvent.KEYCODE_DPAD_LEFT:
            case KeyEvent.KEYCODE_DPAD_RIGHT:
                return super.onKeyDown(keyCode, event);
            case KeyEvent.KEYCODE_BACK:
                hideChannelList();
                return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    private Runnable channelJumpRunnable = () -> {
        if (channelNumberBuilder.length() > 0) {
            try {
                int channelNum = Integer.parseInt(channelNumberBuilder.toString());
                if (channelNum > 0 && channelNum <= allChannels.size()) playChannel(channelNum - 1);
            } catch (NumberFormatException e) {
                e.printStackTrace();
            }
            channelNumberBuilder.setLength(0);
        }
    };

    @Override
    protected void onStart() {
        super.onStart();
        if (player != null) player.setPlayWhenReady(true);
    }

    @Override
    protected void onStop() {
        super.onStop();
        if (player != null) player.setPlayWhenReady(false);
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (player != null) player.release();
    }
}
