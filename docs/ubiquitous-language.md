# Ubiquitous Language

Use Title Case for domain terms in documentation and PascalCase for corresponding type names in code. A domain term does not necessarily require a dedicated class.

## Domain Terms

| Term | Code Type | Meaning |
| --- | --- | --- |
| Earning Line | `EarningLine` | A single earning line containing a System Amount and a history of Manual Adjustments. This is the aggregate boundary. |
| Earning Line Id | `EarningLineId` | The stable identifier of an Earning Line. |
| System Amount | `Money` | The most recent accepted result of an automatic calculation. It cannot change after the first Manual Adjustment. |
| Manual Adjustment | `ManualAdjustment` | An immutable record of a manual change to an Earning Line's amount. |
| Manual Adjustment Id | `ManualAdjustmentId` | The stable identifier of a Manual Adjustment, allowing it to be located unambiguously in the history. |
| Adjustment Amount | `Money` | A signed amount added to the Earning Line's value, rather than a replacement value. |
| Adjustment Comment | — | The mandatory, nonblank explanation for a Manual Adjustment. |
| Adjustment Author Id | `AdjustmentAuthorId` | The identifier of the specialist who made a Manual Adjustment. |
| Recorded At | — | The time a Manual Adjustment was recorded, expressed in UTC. |
| Current Amount | `Money` | The System Amount plus the sum of all Adjustment Amounts. |
| Adjustment History | `AdjustmentHistory` | A read representation containing the System Amount, every Manual Adjustment in recorded order, and the Current Amount. |

`Money` refers to the moneyphp monetary value type. The three amount terms describe distinct business roles of the same value type.

Base Salary is an example of what an Earning Line represents. The model's adjustment rules do not depend on the kind of earning.

## Commands and Events

Commands express intent. Events express accepted facts in the past tense.

| Action | Command | Event |
| --- | --- | --- |
| Create an Earning Line with an initial System Amount | `CreateEarningLine` | `EarningLineCreated` |
| Update the System Amount using a completed automatic calculation | `UpdateSystemAmount` | `SystemAmountUpdated` |
| Add a Manual Adjustment | `AddManualAdjustment` | `ManualAdjustmentAdded` |

The Earning Line receives the result of an external calculation. It does not calculate salary from source data itself; this is why the command is named Update System Amount.

After the first Manual Adjustment, Update System Amount leaves the state unchanged and does not produce a System Amount Updated event.

## Query

**Get Adjustment History** (`GetAdjustmentHistory`) returns an Adjustment History, including the Current Amount.

## Business Rules

1. Before the first Manual Adjustment, the System Amount can be updated.
2. The first Manual Adjustment permanently freezes the most recently accepted System Amount.
3. Every Manual Adjustment remains visible and traceable. Saved adjustments cannot be edited or deleted through the model.
4. A mistake is corrected by adding another Manual Adjustment.
5. The Current Amount equals the System Amount plus all Adjustment Amounts.
6. Automatic updates remain blocked even if the sum of Manual Adjustments returns to zero.

## Contextual Terms

- **Compensating Adjustment** is an ordinary Manual Adjustment that compensates for an earlier mistake. It does not require a separate type or command.
- **Frozen System Amount** describes the System Amount after the first Manual Adjustment. It is not a separate copy of the amount.
- **Adjustment History** presents the system baseline and manual changes. It is distinct from the full technical Event Stream, which also contains accepted System Amount updates before the first Manual Adjustment.

## Technical Vocabulary

Event Stream, Stream Version, and Event Store belong to the implementation vocabulary, rather than the business Ubiquitous Language.
