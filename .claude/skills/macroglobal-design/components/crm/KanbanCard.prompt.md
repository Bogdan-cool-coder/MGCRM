Deal card for the sales pipeline board — title, amount, product chip, owner, days-in-stage, and a bottom health strip.

```jsx
<KanbanCard title="ООО «Ромашка» — внедрение CRM" amount="1 200 000 ₽"
  product="MACRO AI Core" owner="Иван Петров" daysInStage={3} health="ok" task="Звонок · сегодня 14:00" />
<KanbanCard title="Acme Corp — AI pilot" amount="10 $" owner="MG CRM" daysInStage={12} health="no-task" />
<KanbanCard title="Глобус Логистик" amount="180 000 ₽" owner="Анна С." daysInStage={21} health="overdue" rotting task="Встреча просрочена на 3 дня" />
```

`health` drives the strip color + left inset border: `ok` (neutral), `no-task` (amber), `overdue` (red). `rotting` turns the day counter red.
