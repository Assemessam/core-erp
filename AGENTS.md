# Working in CoreERP

- Inspect existing code and documentation before editing.
- Keep changes scoped to the requested milestone. Never implement future phases without explicit instruction.
- Keep controllers thin and business/domain logic outside controllers.
- Use Form Requests for non-trivial HTTP validation, API Resources for API representations, and Policies for authorization.
- Once tenancy exists, protect tenant isolation in every data access path and test cross-tenant denial.
- Use database constraints for appropriate invariants. Never use floating-point values for money.
- Add automated tests for important business rules and failure paths.
- Prefer clear, concrete code; avoid unnecessary abstractions and speculative dependencies.
- Keep backend and frontend independent. Pinia is for application/client state, not every API response.
- Run relevant tests and quality checks before finishing; report anything that could not be verified.
- Document significant architectural decisions in `docs/decisions/`.
- Do not commit, push, or change branches unless explicitly asked.
