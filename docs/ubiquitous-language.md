# Ubiquitous Language

Use Title Case for domain terms in documentation and PascalCase for corresponding type names in code. Methods and properties use camelCase. Enum cases use UPPERCASE (UPPER_SNAKE_CASE for compound names); semantic checks may use predicates such as `isManual()`. This branch uses a state-based model without Event Sourcing.

| Term | Code Type | Meaning |
| --- | --- | --- |
| Earning Line | `EarningLine` | A salary earning line whose state and adjustment history form one aggregate. |
| Earning Line Id | `EarningLineId` | Stable UUID of a line. |
| Initial Amount | `Money` | The original automatic calculation when the line was created. Never changes. |
| System Amount | `Money` | The latest accepted automatic calculation. Permanently frozen by the first Manual Adjustment. |
| Current Amount | `Money` | Sum of all Initial, System and Manual Adjustment Amounts. |
| Earning Line Adjustment | `EarningLineAdjustment` | An immutable, identified record of a signed change to the line. |
| Earning Line Adjustment Id | `EarningLineAdjustmentId` | Stable UUID identifying a correction within a line. |
| Earning Line Adjustment Type | `EarningLineAdjustmentType` | Initial, System or Manual. |
| Initial Adjustment | `EarningLineAdjustmentType::INITIAL` | The original calculation recorded exactly once at sequence 1 when the line is created. Zero is allowed. |
| System Adjustment | `EarningLineAdjustmentType::SYSTEM` | Difference between a new automatic calculation and the previous System Amount. Allowed only before the first Manual Adjustment. |
| Manual Adjustment | `EarningLineAdjustmentType::MANUAL` | Signed correction entered by a specialist, requiring a comment and author. |
| Adjustment Amount | `Money` | Signed delta, never an absolute replacement amount. |
| Adjustment Comment | — | Explanation; mandatory and nonblank for Manual, optional for System. Preserved verbatim. |
| Adjustment Author Id | `AdjustmentAuthorId` | Specialist UUID; mandatory for Manual, absent for automatic recalculations. |
| Recorded At | `CarbonImmutable` | Recording time normalized to UTC with microseconds. History sequence defines order. |
| Adjustment History | `AdjustmentHistory` | Initial Amount, System Amount, all three types of adjustments, Current Amount, and manual precedence status. |
| Compensating Adjustment | — | Another Manual Adjustment that corrects a mistake without changing the earlier record. |

## Commands and Query

- **Create Earning Line** (`CreateEarningLine`) accepts the initial absolute system calculation and records an Initial Adjustment of that amount.
- **Update System Amount** (`UpdateSystemAmount`) accepts a new absolute calculation. The line computes and records a System Adjustment delta. An unchanged amount or frozen line produces no adjustment.
- **Add Manual Adjustment** (`AddManualAdjustment`) accepts the signed delta, adjustment UUID, comment, and author UUID.
- **Get Adjustment History** (`GetAdjustmentHistory`) returns the saved line state and all adjustments in sequence order.

## Invariants

1. Current Amount = sum of Initial, System and Manual Adjustment Amounts.
2. System Amount = sum of Initial and System Adjustment Amounts.
3. The first Manual Adjustment permanently prevents further System Adjustments and freezes System Amount.
4. Compensation, including a net manual sum of zero, never removes that precedence.
5. All saved adjustments are immutable and traceable. Mistakes require new entries.
6. Exactly one Initial Adjustment starts the history. Initial Amount in the line state mirrors that record; it is not added twice.
7. A line uses one currency. Arithmetic uses exact minor-unit strings through MoneyPHP.

For the assignment: Initial Amount is USD 1000.00; one System Adjustment is +USD 50.00; frozen System Amount is USD 1050.00; five Manual Adjustments yield Current Amount USD 1104.45.

Version, optimistic concurrency, database transactions, and persisted state are technical terms rather than additional business concepts. Base Salary is the assignment's example of an Earning Line.
