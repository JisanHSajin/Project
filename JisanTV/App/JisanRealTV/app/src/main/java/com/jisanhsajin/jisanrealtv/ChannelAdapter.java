package com.jisanhsajin.jisanrealtv;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;
import com.bumptech.glide.Glide;
import java.util.List;

public class ChannelAdapter extends RecyclerView.Adapter<ChannelAdapter.ChannelViewHolder> {

    private List<Channel> channels;
    private OnChannelClickListener listener;

    public interface OnChannelClickListener {
        void onChannelClick(int position);
    }

    public ChannelAdapter(List<Channel> channels, OnChannelClickListener listener) {
        this.channels = channels;
        this.listener = listener;
    }

    @NonNull
    @Override
    public ChannelViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View view = LayoutInflater.from(parent.getContext())
                .inflate(R.layout.item_channel, parent, false);
        return new ChannelViewHolder(view);
    }

    @Override
    public void onBindViewHolder(@NonNull ChannelViewHolder holder, int position) {
        Channel channel = channels.get(position);
        holder.channelName.setText(channel.getName());

        Glide.with(holder.itemView.getContext())
                .load(channel.getLogo())
                .placeholder(R.drawable.ic_launcher_foreground)
                .error(R.drawable.ic_launcher_foreground)
                .into(holder.channelLogo);

        holder.itemView.setOnClickListener(v -> {
            if (listener != null) {
                listener.onChannelClick(position);
            }
        });
    }

    @Override
    public int getItemCount() {
        return channels.size();
    }

    static class ChannelViewHolder extends RecyclerView.ViewHolder {
        ImageView channelLogo;
        TextView channelName;

        ChannelViewHolder(View itemView) {
            super(itemView);
            channelLogo = itemView.findViewById(R.id.channelLogo);
            channelName = itemView.findViewById(R.id.channelName);
        }
    }
}
