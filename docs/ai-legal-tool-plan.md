# AI Legal Document Builder — Product & Technical Plan

## 1. Vision
Create a web application that allows customers to assemble templated legal documents through an interactive questionnaire. The system dynamically assembles clauses based on their answers, prices the package, accepts payment, and optionally routes the document to an in-house legal representative for review before delivery.

## 2. Target Users & Personas
- **Individuals** seeking affordable legal documents (e.g., wills, POA, living trusts).
- **Small business owners** needing recurring documents (e.g., NDAs, employment contracts).
- **Internal legal team** responsible for template maintenance and quality control.
- **Customer support & operations** who monitor status, handle payment issues, and liaise with legal reps.

## 3. User Journey Overview
1. Customer launches the embedded widget or visits the standalone site.
2. Selects a document package (e.g., “Basic Will”) and optional add-ons.
3. Completes a guided questionnaire tailored to their selections.
4. System generates a draft PDF/Docx using legal templates and answer data.
5. Customer chooses either:
   - **Instant download** (self-service) once payment succeeds, or
   - **Legal review** (human-in-the-loop). Document enters review queue.
6. Customer tracks progress via a secure portal, receives notifications, and downloads the final version when ready.

## 4. Core Features
### 4.1 Document & Template Management
- Version-controlled clause library with metadata (jurisdiction, required conditions, pricing impact).
- Low-code templating engine (e.g., Liquid, Handlebars) for assembling documents.
- Preview mode for legal team to test questionnaire outcomes.

### 4.2 Questionnaire / Package Builder
- Modular form flow with branching logic based on answers.
- Supports multiple document types; each type references a form schema and template set.
- Autosave and resume functionality for logged-in users.
- Embedded widget script for partners to host the builder on their sites (iframe + JS SDK).

### 4.3 Pricing & Checkout
- Dynamic pricing rules tied to selections and add-ons.
- Integration with payment processor (Stripe recommended: Checkout + Billing for subscriptions, Payment Links fallback).
- Post-payment webhooks to confirm transactions and trigger document release.

### 4.4 Legal Review Workflow
- Review queue UI for internal legal reps.
- Commenting and change request workflow with customer notifications.
- SLA tracking, status updates (Draft → Payment Pending → In Review → Approved → Delivered).

### 4.5 Customer Portal
- Account creation (email/password, social login optional).
- Dashboard showing current and past document requests, payment receipts, status updates, download links.
- Secure document storage with expiring download links.

### 4.6 Admin & Ops Tooling
- Role-based access control for admins, legal reps, support.
- Template editor, questionnaire builder, pricing rule manager.
- Audit logs and analytics (conversion rates, drop-off points, revenue).

### 4.7 Compliance & Security
- SOC 2 / GDPR considerations, data encryption at rest and in transit.
- PII handling guidelines; purge / retention policies.
- Detailed consent and disclaimers, with e-signature for acknowledgment.

## 5. Technical Architecture
### 5.1 Frontend
- React/Next.js SPA for main portal and admin tools.
- Widget bundle delivered as a lightweight JS package; communicates with backend via secure APIs.
- State management via Redux Toolkit or Zustand; form engine powered by JSON schema.

### 5.2 Backend
- Node.js (NestJS) or Python (FastAPI) service orchestrating questionnaires, pricing, and document generation.
- PostgreSQL (core transactional data) + Redis (session cache, job queue).
- Background workers (BullMQ/Celery) for document assembly, PDF rendering, email notifications.

### 5.3 Template & Document Generation
- Templates stored in database or Git-backed repository.
- Use Docx or Markdown templates compiled into PDF/Docx via DocxTemplater, Pandoc, or similar.
- Clause logic encoded using conditional placeholders referencing questionnaire responses.

### 5.4 Authentication & Authorization
- Auth0 or custom JWT-based auth with refresh tokens.
- RBAC enforced via middleware/guards.
- Optional SSO for enterprise clients embedding the tool.

### 5.5 Integrations
- Payment: Stripe (Checkout, Billing, webhooks).
- Notifications: SendGrid/Resend for email, optional SMS (Twilio).
- Storage: AWS S3 or GCP Cloud Storage with signed URLs.
- Analytics: Segment/Amplitude for product analytics.

## 6. Data Model (High-Level)
- **User** (id, role, auth info, profile, preferences).
- **Organization** (for partner accounts, multi-tenant support).
- **DocumentType** (name, description, base price, template refs).
- **Questionnaire** (schema, version, document type FK).
- **ResponseSession** (user, document type, answers JSON, status, pricing breakdown).
- **Order** (pricing snapshot, payment status, Stripe identifiers).
- **DocumentArtifact** (type: draft/final, storage path, checksum, created_at).
- **ReviewTask** (assigned_to, SLA, status, notes).
- **AuditLog** (actor, action, entity_ref, timestamp).

## 7. Implementation Roadmap
1. **Discovery & Validation** (2–3 weeks)
   - Finalize legal templates and compliance requirements.
   - Prototype questionnaire UX and gather feedback from legal team.
2. **MVP (8–10 weeks)**
   - Build backend services (auth, questionnaire engine, payment integration).
   - Create React frontend with embedded widget and customer portal.
   - Implement document generation pipeline for core templates (e.g., basic will).
   - Launch Stripe checkout flow and deliver instant download.
3. **Legal Review Add-On (3–4 weeks)**
   - Introduce review queue, assignment, and notifications.
   - Enable status tracking in portal.
4. **Template Studio & Admin Tools (4–6 weeks)**
   - Visual questionnaire builder, pricing manager, analytics dashboards.
5. **Scale & Compliance**
   - Penetration testing, SOC 2 readiness, data retention automation.

## 8. Open Questions
- Jurisdiction coverage: how to handle state/province-specific clauses and localization?
- AI involvement extent: limited to clause recommendation vs. drafting? Need guardrails for legal accuracy.
- Document signing: integrate e-sign providers (e.g., DocuSign, HelloSign) in future phase?
- Partner integrations: CRM hooks (HubSpot/Salesforce) for lead follow-up?

## 9. Next Steps for Stakeholders
- Validate pricing tiers and upsell strategy with finance team.
- Prioritize document types for initial launch (e.g., wills, NDAs, leases).
- Determine acceptable review turnaround times and staffing.
- Begin security/compliance review and draft privacy policy & terms of service.

