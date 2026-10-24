package tech.shnyr.loyalty.referrals;

public record NewReward(long campaignId, long userId, long inviteeId, int amount) {
}
