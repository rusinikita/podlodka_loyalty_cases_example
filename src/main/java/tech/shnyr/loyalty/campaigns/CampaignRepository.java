package tech.shnyr.loyalty.campaigns;

import java.io.IOException;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.OffsetDateTime;
import java.util.List;
import java.util.Map;
import java.util.Optional;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.ObjectReader;
import org.springframework.dao.DuplicateKeyException;
import org.springframework.jdbc.core.RowMapper;
import org.springframework.jdbc.core.namedparam.MapSqlParameterSource;
import org.springframework.jdbc.core.namedparam.NamedParameterJdbcTemplate;
import org.springframework.stereotype.Repository;

@Repository
public class CampaignRepository {

    private static final String COLUMNS = """
            id, slug, trigger, promo_code, conditions, reward_type, reward_params,
                starts_at, ends_at, is_active, created_by, created_at""";

    private final NamedParameterJdbcTemplate jdbc;
    private final ObjectMapper json;
    private final ObjectReader conditionsReader;
    private final ObjectReader rewardParamsReader;
    private final RowMapper<Campaign> mapper = this::map;

    public CampaignRepository(NamedParameterJdbcTemplate jdbc, ObjectMapper json) {
        this.jdbc = jdbc;
        this.json = json;
        this.conditionsReader = json.readerFor(Conditions.class)
                .without(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES);
        this.rewardParamsReader = json.readerFor(RewardParams.class)
                .without(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES);
    }

    public List<Campaign> activeByTrigger(Trigger trigger, OffsetDateTime now) {
        return jdbc.query("""
                SELECT %s
                FROM campaigns
                WHERE trigger = :trigger
                  AND is_active
                  AND starts_at <= :now
                  AND (ends_at IS NULL OR ends_at > :now)
                ORDER BY id""".formatted(COLUMNS),
                Map.of("trigger", trigger.value(), "now", now), mapper);
    }

    public Optional<Campaign> byPromoCode(String code) {
        return one("""
                SELECT %s
                FROM campaigns
                WHERE promo_code = :code""".formatted(COLUMNS),
                Map.of("code", code));
    }

    public Optional<Campaign> byId(long id) {
        return one("SELECT %s FROM campaigns WHERE id = :id".formatted(COLUMNS), Map.of("id", id));
    }

    public List<Campaign> search(SearchFilter f) {
        MapSqlParameterSource params = new MapSqlParameterSource()
                .addValue("query", f.query() == null ? "" : f.query())
                .addValue("trigger", f.trigger() == null ? "" : f.trigger().value())
                .addValue("active", f.active())
                .addValue("afterId", f.afterId())
                .addValue("limit", f.limit());
        return jdbc.query("""
                SELECT %s
                FROM campaigns
                WHERE (:query = '' OR slug ILIKE '%%' || :query || '%%' OR promo_code ILIKE '%%' || :query || '%%')
                  AND (:trigger = '' OR trigger = :trigger)
                  AND (CAST(:active AS boolean) IS NULL OR is_active = :active)
                  AND id > :afterId
                ORDER BY id
                LIMIT :limit""".formatted(COLUMNS),
                params, mapper);
    }

    public Campaign insert(Campaign c) {
        try {
            return jdbc.queryForObject("""
                    INSERT INTO campaigns
                        (slug, trigger, promo_code, conditions, reward_type, reward_params, starts_at, ends_at, is_active, created_by)
                    VALUES (:slug, :trigger, :promoCode, CAST(:conditions AS jsonb), :rewardType, CAST(:rewardParams AS jsonb),
                            :startsAt, :endsAt, :isActive, :createdBy)
                    RETURNING %s""".formatted(COLUMNS),
                    params(c), mapper);
        } catch (DuplicateKeyException e) {
            throw new CampaignConflictException();
        }
    }

    public Campaign update(Campaign c) {
        try {
            return jdbc.queryForObject("""
                    UPDATE campaigns
                    SET slug = :slug, trigger = :trigger, promo_code = :promoCode, conditions = CAST(:conditions AS jsonb),
                        reward_type = :rewardType, reward_params = CAST(:rewardParams AS jsonb), starts_at = :startsAt,
                        ends_at = :endsAt, is_active = :isActive
                    WHERE id = :id
                    RETURNING %s""".formatted(COLUMNS),
                    params(c), mapper);
        } catch (DuplicateKeyException e) {
            throw new CampaignConflictException();
        }
    }

    private Optional<Campaign> one(String sql, Map<String, ?> params) {
        return jdbc.query(sql, params, mapper).stream().findFirst();
    }

    private MapSqlParameterSource params(Campaign c) {
        try {
            return new MapSqlParameterSource()
                    .addValue("id", c.id())
                    .addValue("slug", c.slug())
                    .addValue("trigger", c.trigger().value())
                    .addValue("promoCode", c.promoCode())
                    .addValue("conditions", json.writeValueAsString(c.conditions()))
                    .addValue("rewardType", c.rewardType().value())
                    .addValue("rewardParams", json.writeValueAsString(c.rewardParams()))
                    .addValue("startsAt", c.startsAt())
                    .addValue("endsAt", c.endsAt())
                    .addValue("isActive", c.isActive())
                    .addValue("createdBy", c.createdBy());
        } catch (JsonProcessingException e) {
            throw new IllegalStateException("encode campaign " + c.slug(), e);
        }
    }

    private Campaign map(ResultSet rs, int row) throws SQLException {
        try {
            return new Campaign(
                    rs.getLong("id"),
                    rs.getString("slug"),
                    Trigger.of(rs.getString("trigger")),
                    rs.getString("promo_code"),
                    conditionsReader.readValue(rs.getString("conditions")),
                    RewardType.of(rs.getString("reward_type")),
                    rewardParamsReader.readValue(rs.getString("reward_params")),
                    rs.getObject("starts_at", OffsetDateTime.class),
                    rs.getObject("ends_at", OffsetDateTime.class),
                    rs.getBoolean("is_active"),
                    rs.getString("created_by"),
                    rs.getObject("created_at", OffsetDateTime.class));
        } catch (IOException e) {
            throw new SQLException("decode campaign " + rs.getLong("id"), e);
        }
    }
}
