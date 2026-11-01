=== eWebCity Education ===
Contributors: mahim
Tags: school management, student portal, attendance, exams, fees
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete school system for WordPress, with portals for students, parents, teachers and the management committee.

== Description ==

eWebCity Education runs a whole school from one plugin.

**School office**

* Schools, sessions, classes, sections and subjects
* Online registration with office approval, admission inquiries
* Student records, ID cards, promotion, transfer, certificates
* Attendance by day or subject, leave requests, staff attendance
* Timetables, study materials, homework, noticeboard, events
* Exams, papers, admit cards, results, assessments and academic reports
* Fees, invoices, payments (bank transfer, cash or online), income and expenses
* Library, transport and hostel
* Email and SMS notifications

**Portals**

* Student and parent portal on your website: attendance, results, fees, timetable, ID card and notices
* Teacher Panel: Teacher Insights dashboard, Student 360° profiles and a Gradebook, limited to the teacher's own class
* Committee Panel: school figures, approvals for payments, expenses, discounts and admissions, and staff management

**Security**

* Activity log of every save, delete, payment and sign-in, with CSV export
* Live activity panel that highlights new changes
* Email alert on any money-related change
* Branded login and white-label admin

== Installation ==

1. Upload the `ewebcity-education` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload.
2. Activate the plugin. The Setup Wizard opens.
3. Tick the pages you need (portal, registration, fees and so on) and press **Create my pages**.
4. In **eWebCity Setup**, add your school, session and classes.
5. Add staff under **Staff & Admin → Staff**. Give teachers the Teacher role and a class.
6. For committee members, create users with the **Committee** role and link them as staff with the Committee role.

Full instructions are under **eWebCity → Documentation** in the admin.

== Shortcodes ==

* `[school_management_account]` student and parent portal
* `[school_management_registration school_id="1"]` online registration
* `[school_management_inquiry school_id="1"]` admission inquiry
* `[school_management_fees school_id="1"]` fee payment
* `[school_management_noticeboard school_id="1"]` noticeboard
* `[school_management_exam_time_table]` exam timetable
* `[school_management_exam_admit_card]` admit card
* `[school_management_exam_result]` exam results
* `[school_management_certificate]` certificates
* `[school_management_invoice_history]` invoice history
* `[school_management_staff_registration]` staff registration
* `[school_management_lesson]` lessons

== Frequently Asked Questions ==

= Can I deactivate the plugin? =

Deactivating it switches off the whole school system on the website. Your data stays in the database and returns when you activate it again.

= How do I keep my own design? =

Change the design, then press **Copy current design into plugin** under eWebCity → Documentation.

== Changelog ==

= 2.1.0 =
* Setup Wizard, Documentation page and portable design file.
* School Engine bundled inside the plugin.

= 2.0.0 =
* Teacher Panel, Teacher Insights, Student 360°, Gradebook.
* Committee Panel, approvals, activity log, live activity and alerts.

== Credits ==

eWebCity Education © Mahim. It includes the School Engine, GPL software by Weblizar, kept with its original notices in the `engine` folder.
