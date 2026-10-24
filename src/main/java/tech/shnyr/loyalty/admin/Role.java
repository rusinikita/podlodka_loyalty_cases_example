package tech.shnyr.loyalty.admin;

import java.util.Optional;

/**
 * Roles of the admin panel, from the lowest. Each role can do everything the
 * previous ones can (docs/admin_permissions.md).
 */
public enum Role {
    SUPPORT_L1("support_l1"),
    SUPPORT_L2("support_l2"),
    LOYALTY_OPERATOR("loyalty_operator"),
    LOYALTY_ADMIN("loyalty_admin");

    private final String value;

    Role(String value) {
        this.value = value;
    }

    public static Optional<Role> of(String value) {
        for (Role r : values()) {
            if (r.value.equals(value)) {
                return Optional.of(r);
            }
        }
        return Optional.empty();
    }

    public boolean atLeast(Role min) {
        return compareTo(min) >= 0;
    }
}
