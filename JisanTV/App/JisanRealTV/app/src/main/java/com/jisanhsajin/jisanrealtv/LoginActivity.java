package com.jisanhsajin.jisanrealtv;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.Build;
import android.provider.Settings;
import android.util.Log;
import android.view.View;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;

import org.json.JSONObject;

import java.io.IOException;
import java.util.concurrent.TimeUnit;

import okhttp3.*;

public class LoginActivity extends AppCompatActivity {

    private static final String TAG = "LoginActivity";
    private static final String BASE_URL = "https://jisanrealtv-proxy.jisanhsajin.workers.dev";

    private EditText emailInput, passwordInput;
    private Button loginButton;
    private ProgressBar progressBar;
    private OkHttpClient client;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        setContentView(R.layout.activity_login);

        emailInput = findViewById(R.id.emailInput);
        passwordInput = findViewById(R.id.passwordInput);
        loginButton = findViewById(R.id.loginButton);
        progressBar = findViewById(R.id.progressBar);

        client = new OkHttpClient.Builder()
                .connectTimeout(30, TimeUnit.SECONDS)
                .writeTimeout(30, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .build();

        loginButton.setOnClickListener(v -> performLogin());
    }

    private void performLogin() {
        String email = emailInput.getText().toString().trim();
        String password = passwordInput.getText().toString().trim();

        String deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        String deviceName = "Android - App (TV) (" + Build.MODEL + ")";
        String deviceType = "Android - App (TV)";

        if (email.isEmpty() || password.isEmpty()) {
            Toast.makeText(this, "Please fill all fields", Toast.LENGTH_SHORT).show();
            return;
        }

        progressBar.setVisibility(View.VISIBLE);
        loginButton.setEnabled(false);

        try {
            JSONObject jsonObject = new JSONObject();
            jsonObject.put("email", email);
            jsonObject.put("password", password);
            jsonObject.put("device_id", deviceId);
            jsonObject.put("device_name", deviceName);
            jsonObject.put("device_type", deviceType);

            RequestBody body = RequestBody.create(
                    jsonObject.toString(),
                    MediaType.parse("application/json; charset=utf-8")
            );

            Request request = new Request.Builder()
                    .url(BASE_URL + "/app_login.php")
                    .post(body)
                    .addHeader("Content-Type", "application/json")
                    .addHeader("Accept", "application/json")
                    .build();

            client.newCall(request).enqueue(new Callback() {
                @Override
                public void onFailure(Call call, IOException e) {
                    Log.e(TAG, "Network error: " + e.getMessage());
                    runOnUiThread(() -> {
                        progressBar.setVisibility(View.GONE);
                        loginButton.setEnabled(true);
                        Toast.makeText(LoginActivity.this,
                                "Network error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    });
                }

                @Override
                public void onResponse(Call call, Response response) throws IOException {
                    String responseData = response.body().string();
                    Log.d(TAG, "Login response received");

                    runOnUiThread(() -> {
                        progressBar.setVisibility(View.GONE);
                        loginButton.setEnabled(true);

                        try {
                            JSONObject json = new JSONObject(responseData);

                            if (json.getBoolean("success")) {
                                SharedPreferences prefs = getSharedPreferences("TVApp", MODE_PRIVATE);
                                SharedPreferences.Editor editor = prefs.edit();
                                editor.putBoolean("is_logged_in", true);
                                editor.putString("user_id", json.getString("user_id"));
                                editor.putString("user_name", json.getString("user_name"));
                                editor.putBoolean("subscription_active",
                                        json.optBoolean("subscription_active", false));
                                editor.apply();

                                Toast.makeText(LoginActivity.this,
                                        "Welcome " + json.getString("user_name") + "!", Toast.LENGTH_SHORT).show();

                                startActivity(new Intent(LoginActivity.this, MainActivity.class));
                                finish();
                            } else {
                                Toast.makeText(LoginActivity.this,
                                        json.getString("message"), Toast.LENGTH_LONG).show();
                            }
                        } catch (Exception e) {
                            Log.e(TAG, "JSON error: " + e.getMessage());
                            Toast.makeText(LoginActivity.this,
                                    "Server error. Please try again.", Toast.LENGTH_SHORT).show();
                        }
                    });
                }
            });

        } catch (Exception e) {
            Log.e(TAG, "Error: " + e.getMessage());
            runOnUiThread(() -> {
                progressBar.setVisibility(View.GONE);
                loginButton.setEnabled(true);
                Toast.makeText(LoginActivity.this,
                        "Error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
            });
        }
    }
}
