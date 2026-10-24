package tech.shnyr.loyalty.campaigns;

public class InvalidCampaignException extends RuntimeException {

    public InvalidCampaignException(String message) {
        super("invalid campaign: " + message);
    }
}
