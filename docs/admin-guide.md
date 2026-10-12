# Administrator guide

This guide is for the store owner and staff who run the affiliate programme day to day. It explains what each staff role can do and how to do each task in the staff area. Money is in Nepalese rupees (NPR). All dates and times are Nepal time (Asia/Kathmandu).

The system checks every permission itself. If a button is missing for you, your role does not include that task. Ask a Super Administrator.

---

## 1. Getting started

### 1.1 Create the first administrator (one time, on the server)

There is no default admin account and no default password. Whoever manages the server runs this once:

```bash
php artisan affiliate:create-admin
```

1. Type the full name, email address and password when asked.
2. The password needs at least 12 characters, with upper- and lower-case letters and a number.
3. The account is created as a **Super Administrator** with its email already verified.

The command refuses to run again once a Super Administrator exists. To add a second one, run `php artisan affiliate:create-admin --additional`.

Accounts for the other staff roles are created the same way, on the server:

```bash
php artisan affiliate:create-staff --role=affiliate_manager    # or finance_manager, support
php artisan affiliate:set-user-status person@example.com disabled --reason="Left the company"
```

Disabling signs the person out on every device. Once the first Super Administrator can sign in, it is easier to add, disable and re-enable staff on the **Staff** screen (1.4). The commands still work, for example if nobody can sign in. Staff accounts can never join the affiliate programme.

### 1.2 Signing in

1. Open the website and choose **Log in**. Affiliates, customers and staff all use the same page.
2. Enter your **Email** and **Password**, then press **Log in**.
3. Staff go straight to the staff area (`/admin`). If you forget your password, use **Forgot password?**.
4. Press **Log out** when you finish, especially on a shared computer.

Every sign-in, failed sign-in and sign-out is written to the audit log. Repeated wrong passwords are slowed down. A disabled account cannot sign in.

### 1.3 Roles and what they can do

| Task | Super Admin | Affiliate Manager | Finance Manager | Customer Support |
|---|---|---|---|---|
| See affiliates, orders, attribution | yes | yes | yes | yes |
| Approve / reject applications, add notes | yes | yes | | |
| Suspend, reinstate, deactivate, reactivate affiliates | yes | yes | | |
| Publish new affiliate terms | yes | | | |
| Products and categories | yes | | | |
| Coupons, campaigns, marketing materials | yes | yes | | |
| Cancel unpaid orders, delivery steps | yes | | | |
| Change an order's affiliate (manual attribution) | yes | yes | | |
| Confirm payment received, check online payments | yes | | yes | |
| Record refunds, open and close disputes | yes | | yes | |
| Commission rules and plans: change | yes | yes | | |
| Commission rules and plans: view | yes | yes | yes | |
| Hold, release hold, void commissions | yes | yes | | |
| Fraud review | yes | yes | | |
| Release approved commissions | yes | | yes | |
| Propose / approve adjustments | yes | | yes | |
| See payouts | yes | yes | yes | |
| Approve, reject, confirm transfer of payouts | yes | | yes | |
| Reconciliation | yes | | yes | |
| Dashboard and reports | yes | yes | yes | one report only |
| Export reports (CSV / Excel) | yes | | yes | |
| Audit log | yes | | | |
| Staff accounts | yes | | | |
| Programme settings | yes | | | |
| Your own name, phone and password | yes | yes | yes | yes |

The left menu shows only the pages your role can open: **Dashboard**, **Finance**, **Reports**, **Affiliates**, **Orders**, **Commissions**, **Adjustments**, **Payouts**, **Commission rules**, **Fraud review**, **Reconciliation**, **Referral report**, **Campaigns**, **Coupons**, **Marketing materials**, **Products**, **Affiliate terms**, **Audit log**, **Staff**, **Settings**. Everyone also has **My profile**.

**Separation of duties built into the system**

- Nobody can approve their own adjustment. A second person must approve it.
- Staff can never change their own affiliate membership. Staff accounts cannot apply to be affiliates at all.
- Optionally, the person who approves a payout cannot also confirm its transfer (see 6.3).

Customer email addresses and phone numbers are partly hidden on every staff screen. To find an order by email, type the customer's full email address.

### 1.4 Staff accounts (Super Administrator)

Open **Staff**. Active accounts come first, then disabled ones. Each row shows the name, email, role, status and when the person last signed in. Your own row is marked **(you)** and has no buttons.

**Add a staff member**

1. Press **Add staff member**.
2. Fill in **Full name** and **Email**, and choose a **Role**: Super Administrator, Affiliate Manager, Finance Manager or Customer Support. A short note under the role says what it can do.
3. Press **Send invitation**.

The person gets an email, "Your staff account for ...", with a **Set your password** button. The link works for as long as a password reset link (60 minutes). Their email address counts as verified, because only its owner can use the link. They can then sign in at the normal **Log in** page.

If the link expired or the email went astray, press **Resend invitation** on their row. The button shows only for an active account that has never signed in. Once someone has signed in, an invitation cannot be sent again; they use **Forgot password?** on the sign-in page instead.

An email address that already has an account (staff, affiliate or customer) cannot be added again.

**Disable or re-enable**

1. Press **Disable** (or **Re-enable**) on the row.
2. Give a **Reason (kept in the audit log)**, at least 5 characters.
3. Press **Disable account** (or **Re-enable account**).

Disabling signs the person out on every device at once, and they cannot sign in until re-enabled. You cannot disable yourself, and the last active Super Administrator cannot be disabled. Add another one first.

**Change someone's role**

1. Press **Change role** on the row. It shows for active accounts other than your own.
2. Choose the new **Role**. A short note says what it can do.
3. Press **Change role**.

The person is signed out on every device at once and signs in again with the new access. You cannot change your own role, and the last active Super Administrator cannot be moved to another role. Make someone else a Super Administrator first. The audit log records the old and new role, so it still shows who did what under which role.

Adding, resending, changing roles, disabling and re-enabling are all recorded in the audit log. The server commands in 1.1 (create and disable) follow the same rules and are recorded the same way.

### 1.5 Programme settings (Super Administrator)

Open **Settings** to change the programme rules without asking the developer. The settings are grouped:

| Group | Settings |
|---|---|
| Referrals | Which click gets the order (most recent or first), attribution window in days, whether an affiliate coupon beats an earlier click, days before a rejected applicant may apply again |
| Commission | Holding period in days, whether commission is released automatically after it |
| Payouts | Minimum payout, whether a different person must mark a payout paid (6.3) |
| Store | Delivery charge per order |
| Fraud alerts | The limits for each fraud signal (5.5) |

1. Change one or more values. Amounts are in rupees. Each value has a lowest and highest allowed figure.
2. Press **Save N change(s)** at the top. A value outside its limits is shown in red with the allowed range, and nothing is saved until you fix it.

Changes apply straight away for everyone. Under each setting you see its default and, once changed, when and by whom. Press **Use default** to go back to the default.

Some changes affect only what happens next. A new holding period applies to orders paid from now on, and a new attribution rule to clicks from now on. Existing commissions keep the dates they already have.

Every change and every return to the default is recorded in the audit log with the old and new value.

The defaults come from the server's `.env` file. Payment keys, email and other technical settings are not on this screen and stay in `.env`.

### 1.6 My profile

Open **My profile** (bottom of the left menu).

- **Your details**: change your **Full name** and **Phone (optional)**, then press **Save details**. Write the phone with the country code, for example `+9779812345678`. Your email cannot be changed here, because it is how you get back into your account. Ask a Super Administrator, or the developer, if it must change.
- **Change password**: enter your **Current password**, then the **New password** twice. The rules are the same as at sign-up, and the new password must differ from the current one. You stay signed in on this device. Every other device and API token is signed out, and you get a "Your password was changed" email.

---

## 2. Affiliates

### 2.1 Reviewing applications

1. Open **Affiliates**. Pending applications are listed first, oldest first.
2. Use the tabs (**All**, **Pending**, **Approved**, **Suspended**, **Rejected**, **Deactivated**) or search by name, email or referral code.
3. Click a name to open the profile. Check **Applicant**, **Promotion plan** (channels, links, audience) and **Terms accepted**.
4. If a red box says **Possible duplicate accounts**, open the linked profiles before deciding.
5. Press **Approve** or **Reject**.
   - **Approve** gives the member a referral code and affiliate access. Anything typed in **Message to the member (optional)** is included in the approval email.
   - **Reject** needs a **Reason (shown to the member)** of at least 5 characters. The reason is emailed to the applicant. A rejected applicant can apply again after 30 days.
6. **Internal note (staff only, optional)** is kept in the status history and is never shown to the member.

New applications appear in the in-app notifications of everyone who can review them.

### 2.2 Suspend, reinstate, deactivate, reactivate

All four need a reason (at least 5 characters) that is shown to the member and emailed to them.

| Button | Available when | What happens |
|---|---|---|
| **Suspend** | Approved | New orders are not credited to the affiliate. Payouts cannot be approved or paid. |
| **Reinstate** | Suspended | Back to Approved. |
| **Deactivate** | Approved or Suspended | Membership closed and affiliate access removed. |
| **Reactivate** | Deactivated | Re-opened with the same referral code. |

Every change is recorded under **Status history** with who did it and when, and in the audit log.

### 2.3 Internal notes

On the profile, under **Internal notes**, type in **Add a note** and press **Add note**. Only staff see notes. Notes cannot be edited or deleted.

### 2.4 Commission plan for one affiliate

On the profile, in the **Commission** card, choose a **Commission plan** (or **Global default**), fill in **Reason for the change**, and press **Save plan**. The new plan applies only to commission created from now on.

### 2.5 Affiliate terms

Open **Affiliate terms** to read every published version. Applicants cannot apply until the first version is published.

To publish a new version (Super Administrator only):

1. Under **Publish a new version**, enter a **Version label** (for example `2026-11`), a **Title** and the **Terms text** (at least 50 characters).
2. Press **Publish version**.

Published terms **cannot be edited or removed**. Approved affiliates are asked to accept each new version. Have a lawyer review the text first.

---

## 3. Catalogue, coupons, campaigns and marketing materials

### 3.1 Products and categories (Super Administrator)

1. Open **Products** and press **New product**, or **Edit** on a row.
2. Fill in **Name**, **SKU**, **URL slug** (for example `metal-nfc-card`), **Price (NPR)**, **Category** and **Description**.
3. Untick **Visible in the shop** to hide a product. Products are hidden, never deleted.
4. Press **Save**.

**Product photo**

Each product can have one photo, shown in the shop and on the product page.

1. Open the product with **Edit**.
2. Under **Photo**, press **Add photo** (or **Replace photo**) and choose a JPG, PNG or WebP file of up to 5 MB, each side under 6,000 pixels.
3. The photo is saved at once; you do not need to press **Save**. To take it off, press **Remove photo**.

A new product gets its photo after its first save. A square photo about 1,200 pixels wide looks best.

The system checks the file's real content, not its name. Other types, including SVG and GIF, are refused. A replaced or removed photo is deleted from the server.

**Categories**

To add a category, type it under **Categories** and press **Add**. The web address is made from the English letters in the name; a name with none (for example in Nepali script) gets a generated address. If the category already exists, a message says so.

- **Rename** changes the name. The web address stays the same, so shared shop links keep working.
- **Hide** takes the category out of the shop's category filter. Its products stay in the shop; hide them one by one if needed. **Show** brings it back.
- **Delete** works only for a category nobody uses. It is refused while products are in it, while past orders refer to it, or while a commission rule uses it. Move the products, or hide the category instead.

Photo changes and category changes are recorded in the audit log.

### 3.2 Coupons (Super Administrator, Affiliate Manager)

1. Open **Coupons** and press **New coupon**, or **Edit**.
2. **Code**: letters, numbers, `-` and `_`. It is stored in capitals. It cannot change once a customer has used it.
3. **Affiliate**: choose one to make it that affiliate's coupon, or **None (store-wide coupon)**. A sale made with an affiliate's coupon is credited to that affiliate, even if the customer clicked another affiliate's link.
4. **Campaign**: choose a campaign to link the coupon to it, or **None**. Orders made with the coupon then count for that campaign and earn its rate (see 3.3). Ended campaigns are not offered.
5. **Type**: **Percentage** (up to 100%) or **Fixed amount**. Then **Percent off** or **Amount off (NPR)**.
6. Optional limits: **Minimum order (NPR)**, **Maximum discount (NPR)**, **Total uses**, **Uses per customer**, **Starts**, **Ends**.
7. **Applies to**: tick products, or tick none for all products.
8. Untick **Active** to switch the coupon off. Press **Save**.

Example: code `DASHAIN10`, Percentage 10, Maximum discount NPR 500, Ends 2026-10-31.

The coupon list shows the linked campaign after the other details, for example "campaign: Dashain sale".

### 3.3 Campaigns (Super Administrator, Affiliate Manager)

A campaign is a promotion with set dates, for chosen products and chosen affiliates. It can pay its own commission rate.

**Create or change a campaign**

1. Open **Campaigns** and press **New campaign**, or **Edit** on a row.
2. **Name**: affiliates see this name.
3. **Description (optional)**: what to promote and how. Affiliates see this too.
4. **First day** and **Last day (optional)**. The campaign starts at the beginning of the first day and ends at the end of the last day, Nepal time. Leave the last day empty for no end date.
5. **Commission budget (NPR, optional)**: for tracking only. Commission is **not** stopped when the budget is reached.
6. Leave **Open to all affiliates** ticked, or untick it and tick at least one name under **Affiliates taking part**.
7. **Products**: tick the products the campaign covers. Tick none to cover all products.
8. **Active (untick to end the campaign now)**.
9. Press **Save**.

Campaigns are never deleted, because orders and rules point to them. To stop a campaign, untick **Active** or set a **Last day**.

The list shows each campaign as **Running**, **Upcoming** or **Ended**, with its dates, affiliates and products. It also shows:

- **Commission**: the campaign's rate, or "no campaign rule in force (normal rates apply)".
- Paid orders, sales and commission so far. Sales are product prices after discounts. Commission is net of any reversals. Orders count as paid when their status is Paid or Partly refunded.
- The budget, if you set one. A red line says when commission has gone over it.

**Give the campaign its own rate**

A campaign has no rate of its own until you add one in **Commission rules** (5.2):

1. Open **Commission rules** and press **New rule**.
2. **Applies to**: **A campaign**. Under **Which one**, choose the campaign. Ended campaigns are not listed.
3. Choose **Percentage of what the customer paid** or **Fixed amount per unit**. A campaign rule cannot exclude products.
4. Set **Starts** and **Ends** to match the campaign's dates, then press **Save rule**.

The campaign rate beats affiliate overrides, product, category and plan rates. Product and category exclusions still win. It applies only to the products the campaign covers; other products in the same order earn the normal rate. The rule must be in force when the order is placed.

**How an order joins a campaign**

The campaign is chosen at checkout, from the first of these that applies:

1. the affiliate coupon the customer used, if it is linked to a campaign;
2. the tracking link the customer clicked, if the affiliate made it for a campaign;
3. a store-wide coupon used on the order, if it is linked to a campaign.

It counts only if the campaign is running at that moment and the affiliate who gets the sale takes part in it. An order that comes through the affiliate's main link, or a referral code typed at checkout, does not join a campaign. It earns the normal rates.

Commission is worked out when the order is paid. If you untick **Active** before then, the order earns the normal rates instead.

Affiliates see the campaigns open to them on their **Referral links** page and make campaign links there. To give an affiliate a campaign coupon, create a coupon with both the **Affiliate** and the **Campaign** filled in (3.2).

Example: "Tihar metal cards", 1 to 15 November, one product, all affiliates, with a 15% campaign rule for the same dates. Orders through a Tihar campaign link earn 15% on the metal card, and the normal rate on anything else in the basket.

### 3.4 Marketing materials (Super Administrator, Affiliate Manager)

Banners, photos, videos, PDFs, ZIP media kits and ready-to-post text for affiliates. Affiliates find them under **Marketing materials** in their account.

1. Open **Marketing materials** and press **Add material**.
2. **Title**: affiliates see this, and it also names the downloaded file.
3. **File (optional for post text)**: a JPG, PNG, WebP or GIF image, an MP4 video, a PDF or a ZIP file, up to 20 MB. The system checks the file's real content, not its name. Other types, including SVG images, are refused.
4. **Description (optional)**: where and how to use it.
5. **Post text (optional)**: text affiliates can copy with one tap. A material with no file must have post text.
6. **Campaign**: **None (always shown)**, or a campaign. A campaign's materials are shown only while it runs, and only to affiliates taking part.
7. **Product**: optional. It is shown on the card as a label.
8. Leave **Shown to affiliates** ticked, or untick it to hide the material. Press **Save**.

Each card shows how many times affiliates have downloaded it. Image previews do not count as downloads.

You cannot change a material's file. Add a new material with the new file and **Delete** the old one. Deleting removes the file for good.

---

## 4. Orders

Open **Orders**. Filter by status (**Awaiting payment**, **Paid**, **Cancelled**) or attribution (**Attributed**, **Blocked**, **No referral**). You can also search by order number or full email. Click an order to open it.

### 4.1 Reading an order

- **Order**: lines, discount, delivery charge, total, customer.
- **Attribution**: which affiliate gets credit and why (link, coupon or manual), or why it was blocked (for example **Self-referral**).
- **Payment**, **Commission**, **Delivery**, **Online payments**, **Disputes and chargebacks**.

### 4.2 How payments are confirmed

- **Online payments (eSewa, Khalti) made on the website are confirmed only by the provider's own status check.** The customer coming back to the site proves nothing. The system checks the status itself when the customer returns, then every few minutes for 48 hours.
- **Other payments** (bank transfer, cash on delivery, Fonepay QR, or a wallet payment made outside the website) are confirmed by Finance by hand, with a reference.

### 4.3 Confirm payment received (Finance Manager, Super Admin)

Only confirm money you have seen arrive: on the bank statement, as cash in hand, or in the wallet's merchant dashboard.

1. On an order that is **Awaiting payment**, press **Confirm payment received**.
2. Choose **How it was paid**.
3. Enter the **Reference** (bank or wallet transaction ID, or receipt number). Each reference can be used only once.
4. Check **Amount received (NPR)**. It must equal the order total exactly. Partial payments are not accepted.
5. Press **Confirm payment**.

The order becomes Paid, commission is created (status Pending) and the customer is emailed a receipt. A cancelled order cannot be confirmed. Refund the customer instead.

### 4.4 Online payment attempts and the review queue

The **Online payments** card lists each time the customer tried eSewa or Khalti.

- **Check with eSewa now** / **Check with Khalti now** asks the provider again straight away.
- Some cases are never settled automatically. The attempt is marked **needs review**, and everyone who can confirm payments is emailed and notified in the app. The cases are:
  - the provider reports a different amount;
  - the customer paid online after the order was cancelled, or after it was already paid another way;
  - the provider transaction ID is already used on another payment;
  - the payment is still pending after 48 hours;
  - the provider reports a refund that is not recorded on the order.

The **Finance** page shows these at the top in a red box. To deal with one:

1. Open the order and read the message.
2. Act in the eSewa or Khalti merchant portal (for example, refund the extra payment).
3. If money went back to the customer on a paid order, also record the refund (4.6).
4. Press **Mark as dealt with**, fill in **What you did** (for example "Refunded in Khalti portal, ref 8Kx2..."), and confirm.

### 4.5 Cancel an unpaid order (Super Administrator)

On an **Awaiting payment** order, press **Cancel order**, give a **Reason** and confirm. Paid orders cannot be cancelled. Refund them instead.

### 4.6 Record a refund (Finance Manager, Super Admin)

**Make the refund first** in the bank, eSewa or Khalti portal, or hand back the cash. The system does not send money. It only records what you did.

1. On a paid order, press **Record refund**.
2. Enter the amount for each line you refunded (each shows **Up to NPR ...**) and, if needed, the **Delivery charge (NPR)**.
3. Enter the **Refund reference** (the bank or wallet transaction ID of the refund) and a **Reason**.
4. Press **Record refund**.

Commission is reversed only for the refunded amounts. Example: on a NPR 2,000 card with 10% commission, a NPR 1,000 refund reverses NPR 100. If that commission was already paid out, the NPR 100 is taken from the affiliate's next balance. Refunds cannot be undone.

### 4.7 Delivery steps (Super Administrator)

Delivery is separate from payment, so cash-on-delivery orders can ship before they are paid. The steps only go forward.

1. **Start preparing** (optional).
2. **Mark as shipped**: enter the **Courier** (for example Pathao or own rider) and an optional **Tracking number**. The customer is emailed both.
3. **Mark as delivered**.

Cancelled or fully refunded orders cannot be shipped.

### 4.8 Disputes and chargebacks (Finance Manager, Super Admin)

Open a dispute when the bank or wallet tells you a customer is disputing a payment.

1. On a paid order, press **Open a dispute**.
2. Enter the **Case reference**, the **Disputed amount (NPR)** and **What the customer claims**. Only one dispute can be open per order.
3. All unpaid commission on the order goes on hold. Nothing is refunded yet.

When the bank or wallet decides, press **Close dispute**, choose the **Outcome** and add a **Note**:

- **Won: we keep the money**: the holds are released.
- **Lost: the money went back to the customer**: a refund named `DISPUTE-<case reference>` is recorded and commission is reversed. **This cannot be undone.**

### 4.9 Manual attribution (Affiliate Manager, Super Admin)

Use this when a customer proves an affiliate referred them but the system did not record it.

1. In the **Attribution** card, press **Attribute manually** (or **Change affiliate**).
2. Choose an approved affiliate and write a **Reason** (at least 10 characters, for example what evidence the customer showed).
3. Press **Save attribution**.

This is not possible once commission exists on the order, or on cancelled or refunded orders. In that case, void the commission and use an adjustment. The buyer cannot be credited to themselves.

---

## 5. Commissions

### 5.1 How commission works

- Commission is created when an order's payment is confirmed. It is worked out on what the customer paid for each line, after discounts.
- It stays **Pending** for a 14-day holding period after payment, then becomes **Approved**. With automatic release on (the default), it then becomes **Available** to the affiliate straight away. Affiliates are emailed when commission becomes available. The system checks this every hour.
- Affiliates request payouts of their available balance. Paid commission shows as **Paid**.

### 5.2 Commission rules and plans

Open **Commission rules**. Without any rule, paid orders earn no commission. Start with a global default.

For each product in a paid order, the first matching rule wins, in this order: product or category exclusion, campaign rate (only for orders that came through a campaign, see 3.3), affiliate override, product rate, category rate, plan default, global default. The rules in force when the order was placed are used.

To add a rule (Super Admin, Affiliate Manager):

1. Press **New rule** and give it a **Name**.
2. **Applies to**: **Everyone (global default)**, **A commission plan**, **A product category**, **A product**, **One affiliate (override)** or **A campaign**. Then choose **Which one**.
3. **Commission**: **Percentage of what the customer paid** (enter **Rate (%)**, at most 50%), **Fixed amount per unit** (enter **Amount per unit (NPR)**), or, for products and categories only, **No commission (exclude)**.
4. Set **Starts** and optionally **Ends (optional)**. Two active rules for the same target cannot overlap in time.
5. Press **Save rule**.

**Versions.** Pressing **Edit** and saving creates a new version (v2, v3 ...). Commission already created keeps the version it used. To change what a rule applies to, end it and create a new one. Every change is audited.

**Plans** group affiliates who share a default rate. Under **Commission plans**, type a **New plan name** and press **Add plan**. Then add a rule with **Applies to: A commission plan**, and put affiliates on the plan from their profile (2.4).

Finance Managers can read rules and plans but cannot change them.

### 5.3 Hold, release hold, void, release

Open **Commissions**. Filter by **Status**, **Hold** or **Order number**. The cards at the top show **Pending liability**, **Approved**, **Available to affiliates** and **Reversed**.

| Button | Who | When | Effect |
|---|---|---|---|
| **Hold** | Affiliate Manager, Super Admin | Pending or Approved | Stops it becoming available. The reason is not shown to the affiliate. |
| **Release hold** | Affiliate Manager, Super Admin | On hold | Continues as normal. Needs a note of what you checked. |
| **Void** | Affiliate Manager, Super Admin | Pending or Approved | Cancels it for good, for example for confirmed fraud. **Cannot be undone.** |
| **Release** | Finance Manager, Super Admin | Approved, not on hold | Makes it available now. Needed only if automatic release is switched off. |

Commission that is already Available or Paid cannot be voided. Correct it with an adjustment.

### 5.4 Adjustments (two-person approval)

Balances are never edited directly. A correction is a separate entry that a second person must approve.

1. Open **Adjustments** and press **Propose adjustment** (Finance Manager, Super Admin).
2. Choose the **Affiliate**, the **Direction** (**Add to balance** or **Deduct from balance**) and the **Amount (NPR)**.
3. Optionally give the **Related commission ID (optional)**. Write a **Reason** (at least 10 characters).
4. Press **Submit for approval**. The adjustment shows **Waiting for approval**.
5. A **different** finance person opens **Adjustments** and presses **Approve** (note optional) or **Reject** (reason required). The person who proposed it cannot approve it.

Only an approved adjustment changes the affiliate's available balance. Example: a bank returns a paid payout of NPR 3,200. Propose "Add to balance, NPR 3,200" with the bank's note, and have a colleague approve it.

### 5.5 Fraud review (Affiliate Manager, Super Admin)

The system looks for unusual patterns and raises a **fraud flag** for a person to check. **A flag never blocks, holds or cancels anything by itself.** You decide what to do, using the usual tools: hold or void commission (5.3), reject a payout (6.1), or suspend the affiliate (2.2).

**What is checked**

Every night at 04:00 the system checks each approved or suspended affiliate. Each payout request is also checked as it comes in. The limits below are the defaults.

| Flag | Severity | Raised when | What to check |
|---|---|---|---|
| **Unusually high conversion** | Medium | Over the last 30 days, at least 5 paid orders came from link clicks, and they are more than 30% of the affiliate's unique clicks | Self-purchases or orders from friends. Compare customer names and addresses on the referred orders. |
| **Many clicks, no orders** | Low | 1,000 or more unique clicks in the last 30 days, and no paid order | Bought or automated traffic. Check the referrer sites on the affiliate's clicks. |
| **High refund rate** | Medium | Over the last 90 days, at least 5 paid orders, and 30% or more of them refunded in full or in part | Orders placed to earn commission and then refunded. |
| **Sudden jump in earnings** | Medium | Commission in the last 7 days is at least NPR 10,000 and at least 5 times the weekly average of the 8 weeks before | A real campaign, or a burst of self-purchases or stolen-card orders. |
| **Large payout request** | Low | A payout request of NPR 50,000 or more | Check the commissions in the payout before approving. |
| **Payout soon after changing payout details** | High | A payout request within 7 days of the affiliate changing their payout details. Adding details for the first time does not count. | A taken-over account sending money to a new destination. Confirm the change with the affiliate by phone. |

A nightly flag is not raised again for the same affiliate while one is open, or for 30 days after the last one. The two payout flags are raised once for each payout request.

New flags are emailed to staff who can review fraud, and shown in their in-app notifications. Payout flags also go to staff who review payouts.

**Review a flag**

1. Open **Fraud review**. It shows **Open** flags first; use **Confirmed**, **Dismissed** or **All** to see others.
2. Each flag shows the affiliate (click the name to open the profile), when it was raised, the figures that were measured, and **What to check**. A payout flag has a **Go to payouts** link.
3. Look into it. Then press **Confirm problem** or **Dismiss**.
4. Fill in **What you found** (at least 5 characters) and save. A flag can be reviewed only once.

**Confirm problem** only records your finding. Then act: hold or void the commission from the affiliate's page, and reject the payout if one is waiting.

A Super Administrator can change the limits under **Fraud alerts** on the **Settings** screen (1.5). They are first guesses. If you see many flags that turn out fine, raise them.

---

## 6. Payouts

Affiliates request a payout from their dashboard. A request takes the whole available balance (minimum NPR 1,000 unless changed in **Settings**). Reviewers get an in-app notification. The affiliate is emailed at each step.

### 6.1 Review a request

1. Open **Payouts**. The cards show **Waiting for review**, **Approved, not sent**, **Paid out** and **Failed or rejected**. Use the status buttons to filter.
2. Click a payout to see the amount, method, the commissions it covers and the ledger.
3. If a red box says **Open fraud flags on this affiliate**, check them in **Fraud review** (5.5) before approving or paying.
4. Press **Approve** (note optional) or **Reject** (**Reason, shown to the affiliate**). Rejecting returns the money to the affiliate's available balance. A request or an approved payout can be rejected.

A payout for a suspended or deactivated affiliate cannot be approved or paid. Reject it or reinstate the affiliate first.

### 6.2 Pay and confirm the transfer

1. Open the approved payout. The full bank or wallet details are shown only to staff who can confirm transfers, and each view is recorded in the audit log.
2. Send the money from the company bank account or wallet.
3. **Only after the money has left the account**, press **Confirm transfer** and enter the **Transfer reference** (letters, numbers, `.`, `_`, `/`, `-`; no spaces). The affiliate receives this reference.
4. If the bank refused the transfer, press **Transfer failed** instead and explain what happened. The money goes back to the affiliate's balance.

A paid payout is final. If the money comes back later, use an adjustment (5.4).

### 6.3 Separate payer setting

If a Super Administrator has switched on **A different person must mark a payout paid** in **Settings** (1.5), the person who approved a payout cannot confirm its transfer. The screen then says **Another finance person must confirm the transfer, because you approved it.** It is off by default. Turn it on as soon as you have two finance people.

---

## 7. Checks, dashboards and reports

### 7.1 Reconciliation

Every night at 03:30 the system rebuilds every affiliate balance from the commission, adjustment and payout records and compares the results. **Clean** means everything matches. **Drift** means something changed the figures outside the app. The check never fixes anything itself.

Open **Reconciliation** to see past runs and their issues, or press **Run check now** (at most 6 times a minute).

### 7.2 Dashboards

- **Dashboard**: clicks, attributed orders, conversion, sales, commission, top affiliates, products and coupon campaigns, for the **Period** you choose (**Last 7 days**, **Last 30 days**, **This month**, **Custom dates** ...). Customer Support sees only a welcome page.
- **Finance**: the payment review queue, a reconciliation warning, **Total owed**, **Available to pay**, **In payout requests**, **Still in holding period**, the payout queue and **Largest balances owed**.
- **Referral report**: clicks, referred orders and order value by affiliate.

### 7.3 Reports and exports

Open **Reports**, choose a **Report** and a period. Reports include affiliate registrations, clicks and conversions, retention, products, coupon campaigns, commission accruals and reversals, balances owed, payouts, outstanding payout requests, refunds and fraud review outcomes. You see only the reports your role allows.

Finance Managers and Super Admins can download any report **as CSV** or **as Excel**. Every export is recorded in the audit log.

### 7.4 Audit log (Super Administrator)

**Audit log** lists every important action, newest first: **When (Nepal time)**, **Action**, **By** and **Subject**. This includes sign-ins, approvals, rule changes, campaign and marketing material changes, payments, refunds, holds, voids, adjustments, payouts, payout-detail views, exports, fraud flags raised and reviewed, staff accounts added, invited, given a new role and disabled, settings changed and reset, product photo and category changes, and profile and password changes. Entries cannot be edited or deleted from the app.

### 7.5 Notifications

Click **Notifications** in the header (a number shows unread items). Use **Mark read** or **Mark all as read**.

| You receive | When | How |
|---|---|---|
| Affiliate reviewers | New application | In app |
| Payout reviewers | New payout request | In app |
| Payment confirmers | Online payment needs review | Email and in app |
| Reconciliation viewers | Drift found | Email and in app |
| Fraud reviewers | New fraud flags | Email and in app |
| Payout reviewers | New fraud flags on a payout request | Email and in app |

Customers are emailed a payment receipt and shipping details. Affiliates are emailed about their application, status changes, each paid order credited to them and its commission, commission reversed by a refund or by staff, newly available commission, payout steps and payout-detail changes. Everyone gets an email when their password is reset or changed. New staff get an invitation email to set their password. The email to an affiliate about cancelled commission never includes your reason.

---

## 8. Finance routine

**Every day**

1. Open **Finance**. Deal with any online payments in the red review box (4.4).
2. Check the reconciliation card says **Clean**.
3. Match bank, eSewa, Khalti and Fonepay statements against **Orders → Awaiting payment**. Confirm what has arrived (4.3).
4. Record any refunds you made in a portal (4.6).
5. Approve or reject new **Adjustments** proposed by a colleague.

**Every week**

1. Work through **Payouts → Requested**: approve, pay, then **Confirm transfer** with the reference.
2. Check **Approved, not sent** is empty. Nothing should sit approved for long.
3. Review open disputes on orders and close any the bank has decided.
4. Download **Balances owed (payout liability)** and **Refunds** for your records.
5. Look at **Commissions** with **Hold: On hold** and ask the Affiliate Manager about old holds.
6. Check **Fraud review** has no old open flags (Affiliate Manager or Super Admin).

---

## 9. When something looks wrong

| You see | Do this |
|---|---|
| **Drift** on Reconciliation, or a drift email | Stop paying payouts. Note the issues listed and tell your developer. Do not try to fix balances with adjustments until the cause is known. |
| An online payment **needs review** | Do not confirm it by hand. Check the merchant dashboard, act there, record any refund, then **Mark as dealt with** (4.4). |
| A customer says they paid online but the order is still awaiting payment | Press **Check with eSewa/Khalti now**. If the provider still says pending, wait. The system keeps checking for 48 hours. |
| A bank or wallet dispute or chargeback | **Open a dispute** on the order (4.8). Close it when the bank decides. |
| Suspected affiliate fraud, or a fraud flag | **Hold** the commissions, add an internal note on the profile, **Suspend** if needed. **Void** only when confirmed. Record what you found on the flag in **Fraud review** (5.5). |
| A payout went to the wrong account | Tell the owner and the bank at once. A paid payout cannot be reopened. Any correction is an adjustment approved by a second person. |

---

## Known limitations

Features that exist only in the API, and other gaps found while writing this guide, are listed in [known-limitations.md](known-limitations.md).
