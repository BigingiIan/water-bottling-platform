---
title: Maintenance
module: maintenance
order: 45
summary: Equipment, preventive maintenance plans, work orders and downtime.
---

## What this page is for

Maintenance keeps a record of every machine, the preventive tasks that keep it running and the work done on it. A work order is created automatically two days before each preventive task is due, and breakdowns are reported as they happen. Downtime is recorded on every completed work order.

## Tasks

### Report a breakdown

1. Open Maintenance › Equipment and select the machine.
2. Select Report a breakdown, describe what is wrong and choose the priority.
3. If the machine has stopped, set it to Out of service or Under maintenance. Production is told, and anyone scheduling a run on that line sees a warning.

### Add a preventive maintenance plan

1. Open the machine and select Add plan.
2. Enter the task, for example Replace RO membrane, how often it repeats and when it is first due.
3. Add the checklist steps, one per line, and choose who does it.

### Work through a work order

1. Open Maintenance › Work Orders and select a work order.
2. Select Start work when you begin.
3. Select Complete, tick the checklist steps you did, and enter the downtime in minutes, the parts used and any cost.
4. If the machine was down, say whether it is working again. Its status is only changed if you say so.

### Turn a request into a work order

1. Open a request from Production about a machine.
2. Select Create work order. The request moves to In progress and the sender is told.

### See what is coming up

1. Open Maintenance › Maintenance Calendar.
2. Overdue tasks are listed first, then each week's tasks for the next eight weeks.

## What each access level lets you do

- **View:** see equipment, its status and downtime, plans, work orders and the maintenance calendar.
- **Full:** also add and edit equipment and plans, change a machine's status, report breakdowns, and start, complete, assign or cancel work orders.

## Common problems

- **A preventive work order wasn't created.** The plan may be paused, or its next due date is more than two days away.
- **The next due date didn't move.** It moves when a preventive work order from that plan is completed, counting from the completion date.
- **Production still sees a line warning.** A machine on that line is still Out of service or Under maintenance. Change its status once it is working again.
