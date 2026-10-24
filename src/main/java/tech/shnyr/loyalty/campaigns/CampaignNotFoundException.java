package tech.shnyr.loyalty.campaigns;

public class CampaignNotFoundException extends RuntimeException {

    public CampaignNotFoundException(long id) {
        super("campaign %d not found".formatted(id));
    }
}
