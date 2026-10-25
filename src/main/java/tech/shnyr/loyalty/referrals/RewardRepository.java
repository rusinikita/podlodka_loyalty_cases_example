package tech.shnyr.loyalty.referrals;

import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.OffsetDateTime;
import java.util.List;

import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Repository;

@Repository
public class RewardRepository {

    private static final String COLUMNS = """
            id, campaign_id, user_id, invitee_id, amount, status, status_changed_at,
                payout_id::text AS payout_id, created_at""";

    private final JdbcTemplate jdbc;

    public RewardRepository(JdbcTemplate jdbc) {
        this.jdbc = jdbc;
    }

    /**
     * Saves the reward unless the invitee already has one in the campaign.
     *
     * @return false if the reward already exists
     */
    public boolean insert(NewReward r, RewardStatus status) {
        int rows = jdbc.update("""
                INSERT INTO referral_rewards (campaign_id, user_id, invitee_id, amount, status)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (campaign_id, invitee_id) DO NOTHING""",
                r.campaignId(), r.userId(), r.inviteeId(), r.amount(), status.code());
        return rows == 1;
    }

    public List<Reward> byUser(long userId, long afterId, int limit) {
        return jdbc.query("""
                SELECT %s
                FROM referral_rewards
                WHERE user_id = ? AND (? = 0 OR id < ?)
                ORDER BY id DESC
                LIMIT ?""".formatted(COLUMNS),
                RewardRepository::map, userId, afterId, afterId, limit);
    }

    public long countByCampaign(long campaignId) {
        Long n = jdbc.queryForObject("SELECT count(*) FROM referral_rewards WHERE campaign_id = ?", Long.class, campaignId);
        return n == null ? 0 : n;
    }

    public List<Reward> byStatusAfter(RewardStatus status, long afterId, int limit) {
        return jdbc.query("""
                SELECT %s
                FROM referral_rewards
                WHERE status = ? AND id > ?
                ORDER BY id
                LIMIT ?""".formatted(COLUMNS),
                RewardRepository::map, status.code(), afterId, limit);
    }

    public void markPaid(long id, String payoutId) {
        jdbc.update("""
                UPDATE referral_rewards
                SET status = ?, status_changed_at = now(), payout_id = ?::uuid
                WHERE id = ? AND status = ?""",
                RewardStatus.PAID.code(), payoutId, id, RewardStatus.PENDING.code());
    }

    /**
     * Moves the reward from one status to another; a reward that is no longer
     * in from is left as is.
     */
    public void setStatus(long id, RewardStatus from, RewardStatus to) {
        jdbc.update("""
                UPDATE referral_rewards
                SET status = ?, status_changed_at = now()
                WHERE id = ? AND status = ?""",
                to.code(), id, from.code());
    }

    private static Reward map(ResultSet rs, int row) throws SQLException {
        return new Reward(
                rs.getLong("id"),
                rs.getLong("campaign_id"),
                rs.getLong("user_id"),
                rs.getLong("invitee_id"),
                rs.getInt("amount"),
                rs.getShort("status"),
                rs.getObject("status_changed_at", OffsetDateTime.class),
                rs.getString("payout_id"),
                rs.getObject("created_at", OffsetDateTime.class));
    }
}
