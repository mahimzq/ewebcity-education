![eWebCity Education](assets/banner-1544x500.png)

# eWebCity Education

A complete school system for WordPress, with portals for students, parents, teachers and the management committee. By **Mahim** · [facebook.com/eWebcity](https://www.facebook.com/eWebcity)

## What it does

| Area | Features |
| --- | --- |
| School office | Sessions, classes, sections, subjects, admissions with approval, inquiries, student records, ID cards, promotion, transfer, certificates |
| Daily work | Attendance, leave requests, timetables, homework, study materials, noticeboard, events |
| Exams | Exams, papers, admit cards, results, assessments, academic reports, Gradebook |
| Money | Fees, invoices, payments (bank, cash, online), income, expenses |
| Extras | Library, transport, hostel, email and SMS notifications |
| Portals | Student/parent portal, Teacher Panel (Teacher Insights, Student 360°), Committee Panel (approvals, staff) |
| Security | Activity log with CSV export, live activity panel, money-change email alerts, branded login, white-label admin |

## Install

1. Download the latest zip and upload it in **Plugins → Add New → Upload Plugin**.
2. Activate **eWebCity Education**. The Setup Wizard opens.
3. Tick the pages you need and press **Create my pages**.
4. Add your school, session and classes under **eWebCity Setup**.
5. Add staff under **Staff & Admin → Staff** (Teacher role plus a class for teachers).

Shortcodes and step-by-step help are in **eWebCity → Documentation** inside WordPress.

## Shortcodes

| Page | Shortcode |
| --- | --- |
| Student & parent portal | `[school_management_account]` |
| Online registration | `[school_management_registration school_id="1"]` |
| Admission inquiry | `[school_management_inquiry school_id="1"]` |
| Pay school fees | `[school_management_fees school_id="1"]` |
| Noticeboard | `[school_management_noticeboard school_id="1"]` |
| Exam timetable | `[school_management_exam_time_table]` |
| Admit card | `[school_management_exam_admit_card]` |
| Exam results | `[school_management_exam_result]` |
| Certificates | `[school_management_certificate]` |
| Invoice history | `[school_management_invoice_history]` |
| Staff registration | `[school_management_staff_registration]` |
| Lessons | `[school_management_lesson]` |

## Folder layout

```
ewebcity-education/
├── ewebcity-education.php   main plugin (portals, panels, wizard, security)
├── assets/                  design file, icons, banners
├── engine/                  bundled School Engine
├── readme.txt
└── LICENSE
```

## License

GPLv2 or later. eWebCity Education © Mahim. The `engine` folder contains the School Engine, GPL software by Weblizar, kept with its original copyright notices.
