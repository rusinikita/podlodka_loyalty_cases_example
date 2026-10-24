package tech.shnyr.loyalty.discounts;

import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.OffsetDateTime;
import java.util.List;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Repository;

@Repository
public class DiscountRepository {

    private static final String COLUMNS = """
            d.id, d.user_id, d.campaign_id, d.channel, d.payment_id, d.percent, d.amount,
                d.max_amount, d.expires_at, d.used_at, d.used_order_id, d.created_by, d.comment, d.created_at""";

    private final JdbcTemplate jdbc;
    private final ObjectMapper json;

    public DiscountRepository(JdbcTemplate jdbc, ObjectMapper json) {
        this.jdbc = jdbc;
        this.json = json;
    }

    public Discount insert(NewDiscount d) {
        return jdbc.queryForObject("""
                INSERT INTO user_discounts AS d
                    (user_id, campaign_id, channel, payment_id, percent, amount, max_amount, expires_at, created_by, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING %s""".formatted(COLUMNS),
                DiscountRepository::map,
                d.userId(), d.campaignId(), d.channel(), d.paymentId(), d.percent(), d.amount(), d.maxAmount(),
                d.expiresAt(), d.createdBy(), d.comment());
    }

    public List<Discount> active(long userId, OffsetDateTime now) {
        return jdbc.query("""
                SELECT %s
                FROM user_discounts d
                WHERE d.user_id = ? AND d.used_at IS NULL AND d.expires_at > ?
                ORDER BY d.expires_at""".formatted(COLUMNS),
                DiscountRepository::map, userId, now);
    }

    public List<Discount> byUser(long userId, long afterId, int limit) {
        return jdbc.query("""
                SELECT %s
                FROM user_discounts d
                WHERE d.user_id = ? AND (? = 0 OR d.id < ?)
                ORDER BY d.id DESC
                LIMIT ?""".formatted(COLUMNS),
                DiscountRepository::map, userId, afterId, afterId, limit);
    }

    List<Redeemable> redeemable(long userId, OffsetDateTime at) {
        return jdbc.query("""
                SELECT %s, coalesce(c.conditions, '{}') AS conditions
                FROM user_discounts d
                LEFT JOIN campaigns c ON c.id = d.campaign_id
                WHERE d.user_id = ? AND d.used_at IS NULL AND d.expires_at > ?""".formatted(COLUMNS),
                (rs, row) -> new Redeemable(map(rs, row), conditions(rs.getString("conditions"))),
                userId, at);
    }

    public boolean markUsed(long id, long userId, long orderId) {
        int rows = jdbc.update("""
                UPDATE user_discounts
                SET used_at = now(), used_order_id = ?
                WHERE id = ? AND user_id = ? AND used_at IS NULL AND expires_at > now()""",
                orderId, id, userId);
        return rows == 1;
    }

    public long countByCampaign(long campaignId) {
        return count("SELECT count(*) FROM user_discounts WHERE campaign_id = ?", campaignId);
    }

    public long countByUser(long campaignId, long userId) {
        return count("SELECT count(*) FROM user_discounts WHERE campaign_id = ? AND user_id = ?", campaignId, userId);
    }

    public long countPromoUsedThisMonth(long userId) {
        return count("""
                SELECT count(*)
                FROM user_discounts d
                JOIN campaigns c ON c.id = d.campaign_id
                WHERE d.user_id = ?
                  AND c.trigger = 'promo_code'
                  AND d.used_at >= date_trunc('month', now())""", userId);
    }

    private long count(String sql, Object... args) {
        Long n = jdbc.queryForObject(sql, Long.class, args);
        return n == null ? 0 : n;
    }

    private OrderConditions conditions(String raw) {
        try {
            return json.readValue(raw, OrderConditions.class);
        } catch (JsonProcessingException e) {
            throw new IllegalStateException("campaign conditions: " + e.getOriginalMessage(), e);
        }
    }

    private static Discount map(ResultSet rs, int row) throws SQLException {
        return new Discount(
                rs.getLong("id"),
                rs.getLong("user_id"),
                rs.getObject("campaign_id", Long.class),
                rs.getString("channel"),
                rs.getString("payment_id"),
                rs.getObject("percent", Integer.class),
                rs.getObject("amount", Integer.class),
                rs.getObject("max_amount", Integer.class),
                rs.getObject("expires_at", OffsetDateTime.class),
                rs.getObject("used_at", OffsetDateTime.class),
                rs.getObject("used_order_id", Long.class),
                rs.getString("created_by"),
                rs.getString("comment"),
                rs.getObject("created_at", OffsetDateTime.class));
    }
}
