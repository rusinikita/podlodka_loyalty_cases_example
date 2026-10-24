package tech.shnyr.loyalty.campaigns;

public class CampaignConflictException extends RuntimeException {

    public CampaignConflictException() {
        super("slug or promo code already exists");
    }
}
