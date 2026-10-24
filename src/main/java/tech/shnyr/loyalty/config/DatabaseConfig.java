package tech.shnyr.loyalty.config;

import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;

import javax.sql.DataSource;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/**
 * Connection pool to the loyalty database. The DSN is given in the libpq URL
 * form, postgres://user:password@host:port/db?params.
 */
@Configuration
public class DatabaseConfig {

    @Bean
    DataSource dataSource(DatabaseProperties db) {
        URI dsn = URI.create(db.dsn());

        HikariConfig cfg = new HikariConfig();
        StringBuilder url = new StringBuilder("jdbc:postgresql://").append(dsn.getHost());
        if (dsn.getPort() > 0) {
            url.append(':').append(dsn.getPort());
        }
        url.append(dsn.getRawPath());
        if (dsn.getRawQuery() != null) {
            url.append('?').append(dsn.getRawQuery());
        }
        cfg.setJdbcUrl(url.toString());

        String userInfo = dsn.getRawUserInfo();
        if (userInfo != null) {
            int colon = userInfo.indexOf(':');
            cfg.setUsername(decode(colon < 0 ? userInfo : userInfo.substring(0, colon)));
            if (colon >= 0) {
                cfg.setPassword(decode(userInfo.substring(colon + 1)));
            }
        }
        if (db.poolSize() > 0) {
            cfg.setMaximumPoolSize(db.poolSize());
        }
        return new HikariDataSource(cfg);
    }

    private static String decode(String s) {
        return URLDecoder.decode(s, StandardCharsets.UTF_8);
    }
}
