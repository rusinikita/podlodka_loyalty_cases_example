package tech.shnyr.loyalty.campaigns;

public class CampaignExpiredException extends RuntimeException {

    public CampaignExpiredException(long campaignId) {
        super("campaign %d expired".formatted(campaignId));
    }
}
