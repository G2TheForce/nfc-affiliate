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

Disabling signs the person out on every device. There is no staff-management screen yet, so these commands are the only way to add, disable or re-enable staff. Staff accounts can never join the affiliate programme.

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
| Coupons | yes | yes | | |
| Cancel unpaid orders, delivery steps | yes | | | |
| Change an order's affiliate (manual attribution) | yes | yes | | |
| Confirm payment received, check online payments | yes | | yes | |
| Record refunds, open and close disputes | yes | | yes | |
| Commission rules and plans: change | yes | yes | | |
| Commission rules and plans: view | yes | yes | yes | |
| Hold, release hold, void commissions | yes | yes | | |
| Release approved commissions | yes | | yes | |
| Propose / approve adjustments | yes | | yes | |
| See payouts | yes | yes | yes | |
| Approve, reject, confirm transfer of payouts | yes | | yes | |
| Reconciliation | yes | | yes | |
| Dashboard and reports | yes | yes | yes | one report only |
| Export reports (CSV / Excel) | yes | | yes | |
| Audit log | yes | | | |

The left menu shows only the pages your role can open: **Dashboard**, **Finance**, **Reports**, **Affiliates**, **Orders**, **Commissions**, **Adjustments**, **Payouts**, **Commission rules**, **Reconciliation**, **Referral report**, **Coupons**, **Products**, **Affiliate terms**, **Audit log**.

**Separation of duties built into the system**

- Nobody can approve their own adjustment. A second person must approve it.
- Staff can never change their own affiliate membership. Staff accounts cannot apply to be affiliates at all.
- Optionally, the person who approves a payout cannot also confirm its transfer (see 6.3).

Customer email addresses and phone numbers are partly hidden on every staff screen. To find an order by email, type the customer's full email address.

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

## 3. Catalogue and coupons

### 3.1 Products and categories (Super Administrator)

1. Open **Products** and press **New product**, or **Edit** on a row.
2. Fill in **Name**, **SKU**, **URL slug** (for example `metal-nfc-card`), **Price (NPR)**, **Category** and **Description**.
3. Untick **Visible in the shop** to hide a product. Products are hidden, never deleted.
4. Press **Save**.

To add a category, type it under **Categories** and press **Add**. The web address is made from the English letters in the name; a name with none (for example in Nepali script) gets a generated address. If the category already exists, a message says so. Categories cannot be renamed or removed from the screen.

### 3.2 Coupons (Super Administrator, Affiliate Manager)

1. Open **Coupons** and press **New coupon**, or **Edit**.
2. **Code**: letters, numbers, `-` and `_`. It is stored in capitals. It cannot change once a customer has used it.
3. **Affiliate**: choose one to make it that affiliate's coupon, or **None (store-wide coupon)**. A sale made with an affiliate's coupon is credited to that affiliate, even if the customer clicked another affiliate's link.
4. **Type**: **Percentage** (up to 100%) or **Fixed amount**. Then **Percent off** or **Amount off (NPR)**.
5. Optional limits: **Minimum order (NPR)**, **Maximum discount (NPR)**, **Total uses**, **Uses per customer**, **Starts**, **Ends**.
6. **Applies to**: tick products, or tick none for all products.
7. Untick **Active** to switch the coupon off. Press **Save**.

Example: code `DASHAIN10`, Percentage 10, Maximum discount NPR 500, Ends 2026-10-31.

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

For each product in a paid order, the first matching rule wins, in this order: product or category exclusion, affiliate override, product rate, category rate, plan default, global default. The rules in force when the order was placed are used.

To add a rule (Super Admin, Affiliate Manager):

1. Press **New rule** and give it a **Name**.
2. **Applies to**: **Everyone (global default)**, **A commission plan**, **A product category**, **A product** or **One affiliate (override)**. Then choose **Which one**.
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

---

## 6. Payouts

Affiliates request a payout from their dashboard. A request takes the whole available balance (minimum NPR 1,000). Reviewers get an in-app notification. The affiliate is emailed at each step.

### 6.1 Review a request

1. Open **Payouts**. The cards show **Waiting for review**, **Approved, not sent**, **Paid out** and **Failed or rejected**. Use the status buttons to filter.
2. Click a payout to see the amount, method, the commissions it covers and the ledger.
3. Press **Approve** (note optional) or **Reject** (**Reason, shown to the affiliate**). Rejecting returns the money to the affiliate's available balance. A request or an approved payout can be rejected.

A payout for a suspended or deactivated affiliate cannot be approved or paid. Reject it or reinstate the affiliate first.

### 6.2 Pay and confirm the transfer

1. Open the approved payout. The full bank or wallet details are shown only to staff who can confirm transfers, and each view is recorded in the audit log.
2. Send the money from the company bank account or wallet.
3. **Only after the money has left the account**, press **Confirm transfer** and enter the **Transfer reference** (letters, numbers, `.`, `_`, `/`, `-`; no spaces). The affiliate receives this reference.
4. If the bank refused the transfer, press **Transfer failed** instead and explain what happened. The money goes back to the affiliate's balance.

A paid payout is final. If the money comes back later, use an adjustment (5.4).

### 6.3 Separate payer setting

If your developer has switched on "require separate payer", the person who approved a payout cannot confirm its transfer. The screen then says **Another finance person must confirm the transfer, because you approved it.** It is off by default. Turn it on as soon as you have two finance people.

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

**Audit log** lists every important action, newest first: **When (Nepal time)**, **Action**, **By** and **Subject**. This includes sign-ins, approvals, rule changes, payments, refunds, holds, voids, adjustments, payouts, payout-detail views and exports. Entries cannot be edited or deleted from the app.

### 7.5 Notifications

Click **Notifications** in the header (a number shows unread items). Use **Mark read** or **Mark all as read**.

| You receive | When | How |
|---|---|---|
| Affiliate reviewers | New application | In app |
| Payout reviewers | New payout request | In app |
| Payment confirmers | Online payment needs review | Email and in app |
| Reconciliation viewers | Drift found | Email and in app |

Customers are emailed a payment receipt and shipping details. Affiliates are emailed about their application, status changes, newly available commission, payout steps and payout-detail changes.

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

---

## 9. When something looks wrong

| You see | Do this |
|---|---|
| **Drift** on Reconciliation, or a drift email | Stop paying payouts. Note the issues listed and tell your developer. Do not try to fix balances with adjustments until the cause is known. |
| An online payment **needs review** | Do not confirm it by hand. Check the merchant dashboard, act there, record any refund, then **Mark as dealt with** (4.4). |
| A customer says they paid online but the order is still awaiting payment | Press **Check with eSewa/Khalti now**. If the provider still says pending, wait. The system keeps checking for 48 hours. |
| A bank or wallet dispute or chargeback | **Open a dispute** on the order (4.8). Close it when the bank decides. |
| Suspected affiliate fraud | **Hold** the commissions, add an internal note on the profile, **Suspend** if needed. **Void** only when confirmed. |
| A payout went to the wrong account | Tell the owner and the bank at once. A paid payout cannot be reopened. Any correction is an adjustment approved by a second person. |

---

## Known limitations

Features that exist only in the API, and other gaps found while writing this guide, are listed in [known-limitations.md](known-limitations.md).
