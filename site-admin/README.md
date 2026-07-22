# Artist Site Manager

Artist Site Manager is the authenticated operational layer between Artwork Mockups and each public artist website.

## Ownership

- Artwork Mockups owns creation, canonical artworks, mockups, and artwork-to-series relationships.
- Artist Site Manager owns publication decisions, public copy, Constellations placement, print variants, stock, site settings, domains, commerce preparation, orders, and activity.
- The public Artist Website remains a read-only visitor surface.

## Navigation

- Content: Artworks, Series, Studio Notes, Artist, Inquire. Constellations is managed directly on each artwork with one optional country field.
- Store: Stock, Orders. Availability, quantity, price, and currency remain visible for each published artwork; optional sale metadata stays folded until needed.
- Settings: Site, Domain, Payments, Shipping.
- Activity: publication and configuration history.

The Content order mirrors the protected public order:

```text
Artworks · Series · Constellations · Studio Notes · Artist · Inquire
```

## Current synchronization

- Artworks use the existing publication records and existing series-publication validation.
- Series use the existing canonical series records and include their dependent artwork counts.
- Studio Notes use the existing Website Board records.
- Artist identity and domain use the existing artist profile.
- Site title, tagline, inquiry introduction, and contact destination are read by the public site without changing its templates or stylesheet.
- An artwork with a Constellation country feeds the existing public Constellations renderer. An empty country keeps it out of Constellations. Until the first managed entry exists, legacy entries remain available.
- Print variants and stock are isolated from editorial synchronization and are not exposed publicly until checkout is activated.

## Commerce boundary

Artwork Mockups owns one Stripe Connect platform configuration. It is managed once by a system administrator and reused for every artist; platform credentials are never collected from artists or exposed in their Store Admin.

Each artist authorizes a separate Stripe Standard account. Only its `acct_…` identity and operational status are stored for that artist. Checkout becomes available only when the platform configuration, the artist connection, price, stock, shipping, and currency are ready. Charges are created on the connected artist account, so sales, Stripe fees, refunds, disputes, and payouts remain separated by artist.

## Visual protection

The public website and manager have separate visual contracts. Site Manager loads only `site-admin/style.css`; it does not import or modify public website styles. See `design-system/` and `artist-site/docs/VISUAL_LANGUAGE.md`.
