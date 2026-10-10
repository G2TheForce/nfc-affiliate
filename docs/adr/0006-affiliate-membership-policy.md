# ADR 0006: Affiliate membership lifecycle and policies

Status: accepted (implemented in Phase 2)

## States and transitions

| From | To | Who | Reason |
|---|---|---|---|
| (none) | pending | the applicant | none |
| pending | approved | `affiliates.review` | optional message |
| pending | rejected | `affiliates.review` | **required**, shown to the applicant |
| rejected | pending | the applicant, after a 30-day cooldown (`AFFILIATE_REAPPLY_COOLDOWN_DAYS`) | none |
| approved | suspended | `affiliates.suspend` | **required** |
| suspended | approved (reinstate) | `affiliates.suspend` | **required** |
| approved or suspended | deactivated | `affiliates.suspend` | **required** |
| approved | deactivated | the member closing their own membership (password required) | optional |
| deactivated | approved (reactivate) | `affiliates.suspend` | **required** |

There is no draft state. The application form is short enough to submit in one go.

Every transition runs in a transaction with a row lock on the profile. The current state is re-checked under the lock, so two reviewers clicking at the same moment cannot both act. Each transition writes an append-only `affiliate_status_histories` row with:
- the actor,
- a **public reason** (shown to the member), and
- an optional **internal note** (staff only).

Each transition also writes an audit entry and sends a queued notification after the commit.

## Policies

- **One membership per user account.** `affiliate_profiles.user_id` is unique.
- **Staff cannot be affiliates.** Staff approve applications and commissions, so they cannot also earn them. Staff also cannot act on a profile that belongs to them.
- **Suspended members cannot close their own membership**, so closing cannot be used to escape a review.
- **Possible duplicates** are flagged to reviewers: other profiles with the same phone number or website host. This is a prompt to look closer, never an automatic decision. Families share phones and agencies share websites.
- **Referral code:** assigned on first approval. It is 8 random characters from an alphabet without look-alikes (no 0/O, 1/I/L), it is permanent, and it is never reused, even after deactivation and reactivation. The slug is derived from the display name and made unique.
- **The affiliate role** is held while the membership is approved or suspended. Suspended members can still see their history. Earning is blocked by status checks (Phases 3 and 4), not by the role. The role is removed on deactivation and rejection.
- **Terms:** published versions are immutable. Applicants must accept the exact current version they were shown. Approved members are asked to accept each new version. Each acceptance records the time, a hashed IP and the user agent.
- **Notifications** carry only the public reason. Internal notes never leave the staff area. Tests check this for the API, in-app notifications and email.

## Still to decide with the business

- Whether a member must accept new terms before referrals keep earning. Currently they are prompted but not blocked. This will be enforced in Phase 4 if wanted.
- Payout-detail changes (spec section 6) are handled in Phase 5 with re-verification and an audit trail.
