<?php
/**
 * Schema, roles and versioned migrations.
 *
 * Tables are created/altered with dbDelta (additive, non-destructive). Data is
 * never dropped on deactivation; uninstall.php only removes data when the
 * administrator has explicitly enabled "delete data on uninstall".
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Installer {

	public static function activate() {
		self::install();
		NDWD_Cron::schedule();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		NDWD_Cron::unschedule();
		// Intentionally keeps every table and option.
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( 'ndwd_db_version', 0 ) < NDWD_DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = function ( $n ) {
			return NDWD_Helpers::table( $n );
		};

		$sql = array();

		$sql[] = "CREATE TABLE {$t('teams')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  description text NULL,
  color varchar(20) NOT NULL DEFAULT '#2dd4a8',
  permissions longtext NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_active (is_active)
) $c;";

		$sql[] = "CREATE TABLE {$t('team_members')} (
  team_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  is_manager tinyint(1) NOT NULL DEFAULT 0,
  added_at datetime NOT NULL,
  PRIMARY KEY  (team_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('projects')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  client varchar(160) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NULL,
  description text NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  parent_id bigint(20) unsigned NULL,
  is_archived tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('tags')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(80) NOT NULL,
  color varchar(20) NOT NULL DEFAULT '#60a5fa',
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) $c;";

		$sql[] = "CREATE TABLE {$t('tasks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NULL,
  title varchar(255) NOT NULL,
  description longtext NULL,
  project_id bigint(20) unsigned NULL,
  client_id bigint(20) unsigned NULL,
  team_id bigint(20) unsigned NULL,
  priority varchar(20) NOT NULL DEFAULT 'medium',
  status varchar(20) NOT NULL DEFAULT 'pending',
  work_type varchar(40) NOT NULL DEFAULT 'office',
  scheduled_date date NOT NULL,
  due_date date NULL,
  due_time time NULL,
  reminder_at datetime NULL,
  reminder_sent tinyint(1) NOT NULL DEFAULT 0,
  completed_at datetime NULL,
  completed_by bigint(20) unsigned NULL,
  rejected_at datetime NULL,
  rejected_by bigint(20) unsigned NULL,
  client_package_id bigint(20) unsigned NULL,
  package_item_id bigint(20) unsigned NULL,
  package_scope varchar(20) NOT NULL DEFAULT '',
  package_note text NULL,
  source varchar(20) NOT NULL DEFAULT 'manual',
  import_hash char(40) NULL,
  claimed_by bigint(20) unsigned NULL,
  claimed_at datetime NULL,
  is_archived tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id),
  KEY status_sched (status,scheduled_date),
  KEY team_id (team_id),
  KEY project_id (project_id),
  KEY due_date (due_date),
  KEY reminder (reminder_sent,reminder_at),
  KEY import_hash (import_hash),
  KEY client_package_id (client_package_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('task_assignees')} (
  task_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY  (task_id,user_id),
  KEY user_id (user_id)
) $c;";

		// v5: a task can belong to several teams. tasks.team_id stays the lead (current) team;
		// rows with status 'handed_off' are teams that finished their part and handed it on.
		$sql[] = "CREATE TABLE {$t('task_teams')} (
  task_id bigint(20) unsigned NOT NULL,
  team_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  added_by bigint(20) unsigned NOT NULL DEFAULT 0,
  added_at datetime NOT NULL,
  handed_off_at datetime NULL,
  PRIMARY KEY  (task_id,team_id),
  KEY team_id (team_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('task_handoffs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  from_team_id bigint(20) unsigned NOT NULL,
  to_team_id bigint(20) unsigned NOT NULL,
  handed_by bigint(20) unsigned NOT NULL,
  from_status varchar(20) NOT NULL DEFAULT '',
  from_completed_at datetime NULL,
  from_assignees text NULL,
  to_assignees text NULL,
  note text NULL,
  handoff_type varchar(20) NOT NULL DEFAULT 'team',
  from_user_id bigint(20) unsigned NULL,
  to_user_id bigint(20) unsigned NULL,
  to_status varchar(20) NOT NULL DEFAULT '',
  links longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id),
  KEY to_team_id (to_team_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('task_tags')} (
  task_id bigint(20) unsigned NOT NULL,
  tag_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (task_id,tag_id),
  KEY tag_id (tag_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('task_links')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  url text NOT NULL,
  label varchar(200) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('remarks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY task_id (task_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('activity')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  task_id bigint(20) unsigned NULL,
  action varchar(40) NOT NULL,
  details text NULL,
  is_meaningful tinyint(1) NOT NULL DEFAULT 1,
  activity_date date NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_date (user_id,activity_date),
  KEY task_id (task_id),
  KEY activity_date (activity_date)
) $c;";

		$sql[] = "CREATE TABLE {$t('attendance')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  att_date date NOT NULL,
  first_login datetime NULL,
  last_login datetime NULL,
  login_count int(10) unsigned NOT NULL DEFAULT 0,
  activity_count int(10) unsigned NOT NULL DEFAULT 0,
  first_activity datetime NULL,
  last_activity datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_date (user_id,att_date),
  KEY att_date (att_date)
) $c;";

		$sql[] = "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  task_id bigint(20) unsigned NULL,
  type varchar(40) NOT NULL,
  message varchar(500) NOT NULL,
  link varchar(255) NOT NULL DEFAULT '',
  is_read tinyint(1) NOT NULL DEFAULT 0,
  dedupe_key varchar(191) NULL,
  created_at datetime NOT NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY user_read (user_id,is_read)
) $c;";

		$sql[] = "CREATE TABLE {$t('email_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NULL,
  recipient varchar(190) NOT NULL,
  subject varchar(255) NOT NULL,
  type varchar(40) NOT NULL,
  status varchar(20) NOT NULL,
  error text NULL,
  dedupe_key varchar(191) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('reports')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  period char(7) NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'running',
  trigger_type varchar(20) NOT NULL DEFAULT 'auto',
  files longtext NULL,
  error text NULL,
  generated_by bigint(20) unsigned NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  email_status varchar(20) NULL,
  email_error text NULL,
  email_attempts int(10) unsigned NOT NULL DEFAULT 0,
  emailed_at datetime NULL,
  confirmed_at datetime NULL,
  confirmed_by bigint(20) unsigned NULL,
  purged_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY period_version (period,version)
) $c;";

		$sql[] = "CREATE TABLE {$t('clients')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  company varchar(160) NOT NULL DEFAULT '',
  contact_person varchar(160) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  notes text NULL,
  billing_name varchar(160) NOT NULL DEFAULT '',
  billing_contact varchar(160) NOT NULL DEFAULT '',
  billing_whatsapp varchar(40) NOT NULL DEFAULT '',
  billing_email varchar(190) NOT NULL DEFAULT '',
  billing_emails text NULL,
  billing_wa_group varchar(255) NOT NULL DEFAULT '',
  billing_address text NULL,
  gstin varchar(20) NOT NULL DEFAULT '',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_active (is_active)
) $c;";

		$sql[] = "CREATE TABLE {$t('leaves')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  leave_type varchar(30) NOT NULL DEFAULT 'casual',
  start_date date NOT NULL,
  end_date date NOT NULL,
  reason text NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  review_note text NULL,
  cancelled_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_dates (user_id,start_date,end_date),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('client_teams')} (
  client_id bigint(20) unsigned NOT NULL,
  team_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (client_id,team_id),
  KEY team_id (team_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('client_members')} (
  client_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (client_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('packages')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL,
  description text NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  services text NULL,
  billing_period varchar(20) NOT NULL DEFAULT '',
  package_type varchar(20) NOT NULL DEFAULT 'monthly',
  duration_months int(10) unsigned NOT NULL DEFAULT 0,
  start_date date NULL,
  end_date date NULL,
  reset_rule varchar(20) NOT NULL DEFAULT 'monthly',
  scope text NULL,
  terms text NULL,
  remarks text NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;";

		// v7: service catalogue (Video Editing, Poster Design, ... and custom services).
		$sql[] = "CREATE TABLE {$t('package_services')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(60) NOT NULL,
  name varchar(120) NOT NULL,
  unit varchar(40) NOT NULL DEFAULT 'unit',
  unit_plural varchar(40) NOT NULL DEFAULT 'units',
  keywords varchar(255) NOT NULL DEFAULT '',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;";

		// v7: services and quantities included in a reusable package template.
		$sql[] = "CREATE TABLE {$t('package_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  package_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  quantity int(10) unsigned NOT NULL DEFAULT 0,
  notes varchar(255) NOT NULL DEFAULT '',
  sort int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY package_id (package_id)
) $c;";

		// v7: a package allocated to one client. Terms are copied from the template, so
		// editing the template never changes existing allocations or invoices.
		$sql[] = "CREATE TABLE {$t('client_packages')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL,
  project_id bigint(20) unsigned NULL,
  package_id bigint(20) unsigned NULL,
  name varchar(160) NOT NULL,
  package_type varchar(20) NOT NULL DEFAULT 'monthly',
  price decimal(14,2) NOT NULL DEFAULT 0,
  billing_period varchar(20) NOT NULL DEFAULT '',
  start_date date NOT NULL,
  end_date date NULL,
  reset_rule varchar(20) NOT NULL DEFAULT 'monthly',
  reset_when_paused tinyint(1) NOT NULL DEFAULT 0,
  scope text NULL,
  terms text NULL,
  remarks text NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  status_changed_at datetime NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY project_id (project_id),
  KEY package_id (package_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('client_package_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_package_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  service_name varchar(120) NOT NULL,
  unit varchar(40) NOT NULL DEFAULT 'unit',
  unit_plural varchar(40) NOT NULL DEFAULT 'units',
  quantity int(10) unsigned NOT NULL DEFAULT 0,
  notes varchar(255) NOT NULL DEFAULT '',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_package_id (client_package_id)
) $c;";

		// v7: one row per billing cycle of an allocation, with the quantities that applied
		// in that cycle, so earlier cycles keep their own limits after later adjustments.
		$sql[] = "CREATE TABLE {$t('package_cycles')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_package_id bigint(20) unsigned NOT NULL,
  cycle_start date NOT NULL,
  cycle_end date NOT NULL,
  allocated longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY pkg_cycle (client_package_id,cycle_start)
) $c;";

		// v7: package usage ledger. live_task_id equals task_id while the row is reserved or
		// consumed and is cleared on release; its unique key allows one live row per task.
		$sql[] = "CREATE TABLE {$t('package_usage')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_package_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  task_id bigint(20) unsigned NULL,
  live_task_id bigint(20) unsigned NULL,
  cycle_start date NOT NULL,
  quantity int(11) NOT NULL DEFAULT 1,
  state varchar(20) NOT NULL DEFAULT 'reserved',
  over_limit tinyint(1) NOT NULL DEFAULT 0,
  approved_by bigint(20) unsigned NULL,
  note text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  consumed_at datetime NULL,
  released_at datetime NULL,
  release_reason varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY live_task_id (live_task_id),
  KEY pkg_cycle (client_package_id,cycle_start),
  KEY task_id (task_id)
) $c;";

		// v7: money received. Invoice payments get one row each (payment_id, unique);
		// manual income has no payment.
		$sql[] = "CREATE TABLE {$t('income')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  amount decimal(14,2) NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  source varchar(80) NOT NULL DEFAULT 'Other Income',
  received_on date NOT NULL,
  method varchar(20) NOT NULL DEFAULT '',
  destination varchar(160) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NULL,
  project_id bigint(20) unsigned NULL,
  invoice_id bigint(20) unsigned NULL,
  payment_id bigint(20) unsigned NULL,
  remarks text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  voided_at datetime NULL,
  voided_by bigint(20) unsigned NULL,
  void_reason text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY payment_id (payment_id),
  KEY received_on (received_on),
  KEY client_id (client_id),
  KEY invoice_id (invoice_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('invoices')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_no varchar(40) NOT NULL,
  fy char(7) NOT NULL,
  client_id bigint(20) unsigned NOT NULL,
  client_snapshot longtext NULL,
  business_snapshot longtext NULL,
  package_id bigint(20) unsigned NULL,
  client_package_id bigint(20) unsigned NULL,
  package_name varchar(160) NOT NULL DEFAULT '',
  package_amount decimal(14,2) NOT NULL DEFAULT 0,
  invoice_date date NOT NULL,
  due_date date NOT NULL,
  subtotal decimal(14,2) NOT NULL DEFAULT 0,
  discount decimal(14,2) NOT NULL DEFAULT 0,
  discount_label varchar(160) NOT NULL DEFAULT '',
  work_groups longtext NULL,
  tax_label varchar(40) NOT NULL DEFAULT '',
  tax_rate decimal(6,2) NOT NULL DEFAULT 0,
  tax_amount decimal(14,2) NOT NULL DEFAULT 0,
  total decimal(14,2) NOT NULL DEFAULT 0,
  amount_paid decimal(14,2) NOT NULL DEFAULT 0,
  amount_adjusted decimal(14,2) NOT NULL DEFAULT 0,
  expense_subtotal decimal(14,2) NOT NULL DEFAULT 0,
  carried_forward decimal(14,2) NOT NULL DEFAULT 0,
  amount_transferred decimal(14,2) NOT NULL DEFAULT 0,
  consolidated_into bigint(20) unsigned NULL,
  prev_summary longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'unpaid',
  notes text NULL,
  work_remarks text NULL,
  payment_instructions text NULL,
  pdf_path varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  cancelled_at datetime NULL,
  cancel_reason text NULL,
  cancelled_by bigint(20) unsigned NULL,
  replaces_id bigint(20) unsigned NULL,
  replaced_by_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY invoice_no (invoice_no),
  KEY fy (fy),
  KEY client_id (client_id),
  KEY status (status)
) $c;";

		// v9: advances / manual income adjusted against invoices. An allocation never creates
		// or copies income: the Income row stays the single record of the money received.
		$sql[] = "CREATE TABLE {$t('income_allocations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  income_id bigint(20) unsigned NOT NULL,
  invoice_id bigint(20) unsigned NOT NULL,
  client_id bigint(20) unsigned NOT NULL,
  amount decimal(14,2) NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  released_at datetime NULL,
  released_by bigint(20) unsigned NULL,
  release_reason varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY income_id (income_id),
  KEY invoice_id (invoice_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('invoice_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL,
  task_id bigint(20) unsigned NULL,
  description varchar(255) NOT NULL,
  treatment varchar(20) NOT NULL DEFAULT 'custom',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  override_reason varchar(255) NOT NULL DEFAULT '',
  remarks text NULL,
  completed_on date NULL,
  expense_id bigint(20) unsigned NULL,
  source_invoice_id bigint(20) unsigned NULL,
  original_amount decimal(14,2) NULL,
  group_no int(10) unsigned NOT NULL DEFAULT 0,
  client_package_id bigint(20) unsigned NULL,
  package_id bigint(20) unsigned NULL,
  package_type varchar(20) NOT NULL DEFAULT '',
  billing_month char(7) NOT NULL DEFAULT '',
  period_start date NULL,
  period_end date NULL,
  terms text NULL,
  sort int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY task_id (task_id),
  KEY expense_id (expense_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('payments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL,
  amount decimal(14,2) NOT NULL,
  paid_on date NOT NULL,
  method varchar(20) NOT NULL DEFAULT 'other',
  destination varchar(160) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  notes text NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  voided_at datetime NULL,
  voided_by bigint(20) unsigned NULL,
  void_reason text NULL,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY paid_on (paid_on)
) $c;";

		$sql[] = "CREATE TABLE {$t('expenses')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  description text NULL,
  category varchar(80) NOT NULL DEFAULT 'other',
  amount decimal(14,2) NOT NULL,
  expense_date date NOT NULL,
  vendor varchar(160) NOT NULL DEFAULT '',
  method varchar(20) NOT NULL DEFAULT '',
  destination varchar(160) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  receipt_path varchar(255) NOT NULL DEFAULT '',
  receipt_name varchar(190) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NULL,
  project_id bigint(20) unsigned NULL,
  billable tinyint(1) NOT NULL DEFAULT 0,
  billable_amount decimal(14,2) NULL,
  billing_remarks varchar(255) NOT NULL DEFAULT '',
  notes text NULL,
  status varchar(20) NOT NULL DEFAULT 'unpaid',
  paid_on date NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY expense_date (expense_date),
  KEY category (category)
) $c;";

		$sql[] = "CREATE TABLE {$t('fin_entries')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_date date NOT NULL,
  entry_type varchar(20) NOT NULL,
  direction varchar(3) NOT NULL,
  amount decimal(14,2) NOT NULL,
  party varchar(160) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NULL,
  invoice_id bigint(20) unsigned NULL,
  method varchar(20) NOT NULL DEFAULT '',
  destination varchar(160) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  description text NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  voided_at datetime NULL,
  PRIMARY KEY  (id),
  KEY entry_date (entry_date)
) $c;";

		$sql[] = "CREATE TABLE {$t('fin_audit')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity varchar(20) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL,
  action varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  details longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity,entity_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job varchar(40) NOT NULL,
  period varchar(20) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'running',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  next_attempt_at datetime NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_period (job,period)
) $c;";

		$sql[] = "CREATE TABLE {$t('fy_archives')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fy char(7) NOT NULL,
  file varchar(255) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'running',
  error text NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NULL,
  downloaded_at datetime NULL,
  confirmed_by bigint(20) unsigned NULL,
  confirmed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY fy (fy)
) $c;";

		// v11: manual carry-forward decisions. One row per service and cycle, so a cycle's unused
		// units can be decided only once; accepted units reach the next cycle as a ledger adjustment.
		$sql[] = "CREATE TABLE {$t('package_carryovers')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_package_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  from_cycle date NOT NULL,
  to_cycle date NOT NULL,
  unused int(11) NOT NULL DEFAULT 0,
  accepted int(11) NOT NULL DEFAULT 0,
  rejected int(11) NOT NULL DEFAULT 0,
  note text NULL,
  decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  decided_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item_cycle (item_id,from_cycle),
  KEY client_package_id (client_package_id)
) $c;";

		// v11: one row per scheduled delivery (reminder, due today, overdue). run_key is unique, so a
		// delivery can be queued once; claiming it is one conditional UPDATE, so one run processes it.
		$sql[] = "CREATE TABLE {$t('scheduled_jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  run_key varchar(191) NOT NULL,
  task_id bigint(20) unsigned NULL,
  message varchar(500) NOT NULL DEFAULT '',
  scheduled_for datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'scheduled',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NULL,
  claimed_at datetime NULL,
  inapp_created int(10) unsigned NOT NULL DEFAULT 0,
  email_sent int(10) unsigned NOT NULL DEFAULT 0,
  email_failed int(10) unsigned NOT NULL DEFAULT 0,
  email_status varchar(20) NOT NULL DEFAULT '',
  delay_seconds int(11) NOT NULL DEFAULT 0,
  last_error text NULL,
  created_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY run_key (run_key),
  KEY status_next (status,next_attempt_at),
  KEY task_id (task_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('push_subscriptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint text NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh varchar(128) NOT NULL,
  auth varchar(64) NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  fail_count int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_seen_at datetime NULL,
  last_success_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('push_queue')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  notification_id bigint(20) unsigned NOT NULL,
  subscription_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  event varchar(40) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  claimed_at datetime NULL,
  last_error varchar(255) NULL,
  created_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY one_per_device (notification_id,subscription_id),
  KEY status_next (status,next_attempt_at),
  KEY subscription_id (subscription_id)
) $c;";

		$sql = array_merge( $sql, NDWD_WS_Schema::statements() ); // v13: Workspace integration tables.

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$from = (int) get_option( 'ndwd_db_version', 0 );
		self::roles();
		self::defaults();
		self::migrate_clients();
		self::backfill_task_teams();
		if ( $from && $from < 3 ) {
			self::migrate_roles_v3();
		}
		self::migrate_blocked_v6();
		if ( $from && $from < 6 ) {
			self::session_policy_v6();
		}
		NDWD_Packages::seed_services();
		if ( $from && $from < 7 ) {
			self::package_types_v7();
		}
		self::migrate_billing_emails_v7();
		self::backfill_income_v7();
		self::income_categories_v9();
		self::roles();
		if ( $from && $from < 15 ) {
			self::task_visibility_v15();
		}
		if ( $from && $from < 16 ) {
			NDWD_WS_Projects::upgrade_v16();
		}
		if ( $from && $from < 19 ) {
			NDWD_WS_Projects::upgrade_v19();
		}
		if ( $from && $from < 20 ) {
			NDWD_WS::upgrade_v20();
		}
		update_option( 'ndwd_db_version', NDWD_DB_VERSION );
	}

	/**
	 * v15: client visibility now lives on each task. Tasks an admin had individually ticked on a
	 * connection keep what the client could already see. Tasks that were only exposed by the old
	 * "all tasks of visible projects" mode become internal until someone enables them. Rows saved
	 * before this version showed their status, so that switch is kept on for them.
	 */
	private static function task_visibility_v15() {
		global $wpdb;
		$tc  = NDWD_WS::t( 'task_client' );
		$vis = NDWD_WS::t( 'visibility' );
		$now = NDWD_Helpers::now_mysql();
		$wpdb->query( "UPDATE $tc SET show_status = 1 WHERE visible = 1" ); // phpcs:ignore
		foreach ( $wpdb->get_col( "SELECT DISTINCT entity_id FROM $vis WHERE entity = 'task'" ) as $id ) { // phpcs:ignore
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT task_id FROM $tc WHERE task_id = %d", $id ) ) ) { // phpcs:ignore
				$wpdb->insert( $tc, array( 'task_id' => (int) $id, 'visible' => 1, 'show_status' => 1, 'show_deadline' => 1, 'show_calendar' => 1, 'updated_at' => $now ) );
			}
		}
	}

	/**
	 * v9: income categories. "Service Revenue" left the built-in list; categories already
	 * used by stored income (including it) are kept as custom categories so old entries keep
	 * their category, stay filterable and can still be chosen. Stored rows are not changed.
	 * Idempotent.
	 */
	public static function income_categories_v9() {
		global $wpdb;
		$used = $wpdb->get_col( 'SELECT DISTINCT source FROM ' . NDWD_Helpers::table( 'income' ) . " WHERE source <> ''" ); // phpcs:ignore
		$s    = get_option( 'ndwd_accounts', array() );
		$s    = is_array( $s ) ? $s : array();
		$have = isset( $s['income_sources'] ) ? (array) $s['income_sources'] : array();
		$add  = array_diff( (array) $used, NDWD_Accounts::INCOME_SOURCES, $have );
		if ( $add ) {
			$s['income_sources'] = array_values( array_merge( $have, $add ) );
			update_option( 'ndwd_accounts', $s, false );
		}
	}

	/**
	 * v6: "Blocked" is retired. Stored Blocked tasks become Pending, so they stay open and
	 * keep carrying forward exactly as before (nothing disappears from anyone's worksheet).
	 * Only the status column changes; activity history, remarks and handoff records that
	 * mention Blocked are kept as they were, and each change is logged on the task.
	 * Idempotent: once no Blocked rows remain it does nothing.
	 */
	public static function migrate_blocked_v6() {
		global $wpdb;
		$table = NDWD_Helpers::table( 'tasks' );
		$to    = NDWD_Helpers::LEGACY_STATUS_TARGET;
		foreach ( array_keys( NDWD_Helpers::LEGACY_STATUSES ) as $legacy ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status = %s", $legacy ) ); // phpcs:ignore
			foreach ( $ids as $id ) {
				$done = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = %s WHERE id = %d AND status = %s", $to, $id, $legacy ) ); // phpcs:ignore
				if ( $done ) {
					NDWD_Activity::log( 0, (int) $id, 'status_migrated', array(
						'from' => NDWD_Helpers::status_label( $legacy ),
						'to'   => NDWD_Helpers::status_label( $to ),
						'note' => 'Blocked was replaced by Rejected; open Blocked tasks were kept open as Pending.',
					), false );
				}
			}
		}
	}

	/**
	 * v7: packages created before 1.6 only had a billing period. One-time packages become
	 * "One-time", monthly ones "Monthly" (reset each month), longer periods "Fixed duration".
	 */
	public static function package_types_v7() {
		global $wpdb;
		$t = NDWD_Helpers::table( 'packages' );
		$wpdb->query( "UPDATE $t SET package_type = 'one_time', reset_rule = 'none' WHERE billing_period = ''" ); // phpcs:ignore
		$wpdb->query( "UPDATE $t SET package_type = 'monthly', reset_rule = 'monthly' WHERE billing_period = 'monthly'" ); // phpcs:ignore
		$wpdb->query( "UPDATE $t SET package_type = 'fixed', reset_rule = 'none' WHERE billing_period IN ('quarterly','half_yearly','yearly')" ); // phpcs:ignore
	}

	/**
	 * v7: the single billing email becomes the first "To" recipient of the new recipient
	 * list. billing_email is kept (and kept in step) for older code and exports.
	 * Idempotent: only clients whose list is still empty are touched.
	 */
	public static function migrate_billing_emails_v7() {
		global $wpdb;
		$t = NDWD_Helpers::table( 'clients' );
		foreach ( $wpdb->get_results( "SELECT id, billing_email FROM $t WHERE billing_email <> '' AND ( billing_emails IS NULL OR billing_emails = '' )" ) as $c ) { // phpcs:ignore
			$wpdb->update( $t, array( 'billing_emails' => wp_json_encode( array( array( 'email' => $c->billing_email, 'type' => 'to' ) ) ) ), array( 'id' => $c->id ) );
		}
	}

	/**
	 * v7: every invoice payment recorded before 1.6 gets its Income row (voided payments
	 * get a voided row), so Income lists all money received. The unique payment_id key and
	 * the NOT EXISTS check make it safe to run again.
	 */
	public static function backfill_income_v7() {
		global $wpdb;
		$pay = NDWD_Helpers::table( 'payments' );
		$inv = NDWD_Helpers::table( 'invoices' );
		$inc = NDWD_Helpers::table( 'income' );
		$rows = $wpdb->get_results( "SELECT p.*, v.invoice_no, v.client_id FROM $pay p JOIN $inv v ON v.id = p.invoice_id WHERE NOT EXISTS (SELECT 1 FROM $inc i WHERE i.payment_id = p.id)" ); // phpcs:ignore
		foreach ( $rows as $p ) {
			$wpdb->insert( $inc, array(
				'amount'      => $p->amount,
				'description' => 'Payment for ' . $p->invoice_no,
				'source'      => 'Client Payment',
				'received_on' => $p->paid_on,
				'method'      => $p->method,
				'destination' => (string) $p->destination,
				'reference'   => (string) $p->reference,
				'client_id'   => $p->client_id ? (int) $p->client_id : null,
				'invoice_id'  => (int) $p->invoice_id,
				'payment_id'  => (int) $p->id,
				'remarks'     => (string) $p->notes,
				'created_by'  => (int) $p->created_by,
				'created_at'  => $p->created_at,
				'updated_at'  => $p->created_at,
				'voided_at'   => $p->voided_at,
				'voided_by'   => $p->voided_by,
				'void_reason' => $p->void_reason,
			) );
		}
	}

	/**
	 * v6: sessions are no longer cut at midnight IST (that signed everyone out every night).
	 * Attendance for each new IST date is recorded from the signed-in session instead.
	 */
	public static function session_policy_v6() {
		$s = get_option( 'ndwd_settings', array() );
		if ( is_array( $s ) && ! empty( $s['session_until_midnight'] ) ) {
			$s['session_until_midnight'] = 0;
			update_option( 'ndwd_settings', $s );
		}
	}

	/**
	 * v2: turn the free-text "client" on existing projects into client records and
	 * link them. Non-destructive: the old text column is kept as-is.
	 */
	public static function migrate_clients() {
		global $wpdb;
		$projects = NDWD_Helpers::table( 'projects' );
		$clients  = NDWD_Helpers::table( 'clients' );
		$rows     = $wpdb->get_results( "SELECT id, client FROM $projects WHERE client <> '' AND client_id IS NULL" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $clients WHERE name = %s LIMIT 1", $r->client ) );
			if ( ! $id ) {
				$now = NDWD_Helpers::now_mysql();
				$wpdb->insert( $clients, array( 'name' => $r->client, 'created_at' => $now, 'updated_at' => $now ) );
				$id = (int) $wpdb->insert_id;
			}
			$wpdb->update( $projects, array( 'client_id' => $id ), array( 'id' => $r->id ) );
		}
		self::backfill_task_clients();
	}

	/**
	 * v3: only two global roles remain (Admin, Member). Former "Manager" users become
	 * Members; what they managed is kept through the per-team manager flag, and each
	 * team's permissions decide what they can do there. Capabilities that used to be
	 * global but are now team-scoped are removed from individual users. The list of
	 * converted users is kept in the ndwd_v3_role_migration option for reference.
	 */
	public static function migrate_roles_v3() {
		$changed = array();
		foreach ( get_users( array( 'role' => 'ndwd_manager' ) ) as $u ) {
			$changed[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'managed_teams' => NDWD_Permissions::managed_teams( $u->ID ) );
			$u->set_role( 'ndwd_member' );
		}
		foreach ( get_users() as $u ) {
			foreach ( self::RETIRED_CAPS as $cap ) {
				if ( isset( $u->caps[ $cap ] ) ) {
					$u->remove_cap( $cap );
				}
			}
		}
		update_option( 'ndwd_v3_role_migration', array( 'at' => NDWD_Helpers::now_mysql(), 'managers_converted' => $changed ), false );
	}

	/** Former global capabilities that are now granted per team. */
	const RETIRED_CAPS = array( 'ndwd_view_team', 'ndwd_view_reports', 'ndwd_approve_leave' );

	/** Tasks of a project inherit the project's client when they have none of their own. */
	public static function backfill_task_clients( $project_id = 0 ) {
		global $wpdb;
		$tasks    = NDWD_Helpers::table( 'tasks' );
		$projects = NDWD_Helpers::table( 'projects' );
		$sql      = "UPDATE $tasks SET client_id = (SELECT p.client_id FROM $projects p WHERE p.id = $tasks.project_id) WHERE client_id IS NULL AND project_id IS NOT NULL";
		if ( $project_id ) {
			$sql .= $wpdb->prepare( ' AND project_id = %d', $project_id );
		}
		$wpdb->query( $sql ); // phpcs:ignore
	}

	/** v5: every task's existing team becomes its first task_teams row. Additive and repeatable. */
	public static function backfill_task_teams() {
		global $wpdb;
		$tasks = NDWD_Helpers::table( 'tasks' );
		$tt    = NDWD_Helpers::table( 'task_teams' );
		$wpdb->query( "INSERT INTO $tt (task_id, team_id, status, added_by, added_at) SELECT t.id, t.team_id, 'active', t.created_by, t.created_at FROM $tasks t WHERE t.team_id IS NOT NULL AND t.team_id > 0 AND NOT EXISTS (SELECT 1 FROM $tt x WHERE x.task_id = t.id AND x.team_id = t.team_id)" ); // phpcs:ignore
	}

	public static function all_caps() {
		return array(
			'ndwd_access'          => 'Use WorkDesk',
			'ndwd_view_all'        => 'View all teams, tasks, clients and reports',
			'ndwd_edit_all_tasks'  => 'Edit and reassign any task',
			'ndwd_reassign_tasks'  => 'Manage / reassign other members\' tasks',
			'ndwd_archive_tasks'   => 'Archive any task',
			'ndwd_manage_members'  => 'Add, edit, activate and deactivate members',
			'ndwd_manage_teams'    => 'Create teams and manage team membership',
			'ndwd_manage_projects' => 'Manage projects, clients and tags',
			'ndwd_manage_settings' => 'Manage settings, email and option lists',
			'ndwd_manage_reports'  => 'Generate and download monthly archives',
			'ndwd_import'          => 'Import worksheet CSV',
			'ndwd_manage_clients'  => 'Add and edit clients (sees all clients)',
			'ndwd_manage_accounts' => 'Accounts: invoices, payments, income, expenses, packages, package adjustments and financial reports',
			'ndwd_grant_admin'     => 'Grant administrative rights to members',
			'ndwd_delete_records'  => 'Delete records (teams, projects, clients, packages) that nothing depends on',
			'ndwd_manage_workspace' => 'Workspace Management: connections, entitlements, invitations, work requests and sync logs',
			'ndwd_view_consolidated' => 'View and export consolidated leads and bookings from Workspace businesses',
			'ndwd_handle_enquiries' => 'See website enquiries, pick them up, add remarks and send onboarding forms',
			'ndwd_manage_enquiries' => 'Oversee all enquiries: assign, reassign and change their settings',
		);
	}

	public static function role_caps( $role ) {
		// Members get only basic access globally; what they may do inside a team comes from
		// that team's permissions (NDWD_Permissions::TEAM_PERMS), so it can differ per team.
		if ( 'ndwd_member' === $role ) {
			return array( 'read' => true, 'ndwd_access' => true );
		}
		$all = array( 'read' => true );
		foreach ( array_keys( self::all_caps() ) as $cap ) {
			$all[ $cap ] = true;
		}
		return $all;
	}

	public static function roles() {
		$labels = array(
			'ndwd_member' => 'WorkDesk Member',
			'ndwd_admin'  => 'WorkDesk Admin',
		);
		foreach ( $labels as $role => $label ) {
			remove_role( $role );
			add_role( $role, $label, self::role_caps( $role ) );
		}
		// The global Manager role is retired in v3. It is removed only once nobody holds
		// it, so an interrupted migration never leaves a user without a role.
		if ( get_role( 'ndwd_manager' ) && ! get_users( array( 'role' => 'ndwd_manager', 'fields' => 'ID', 'number' => 1 ) ) ) {
			remove_role( 'ndwd_manager' );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::all_caps() ) as $cap ) {
				$admin->add_cap( $cap );
			}
			foreach ( self::RETIRED_CAPS as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	public static function defaults() {
		add_option( 'ndwd_work_types', array( 'office' => 'Office', 'field' => 'Field / On-site', 'remote' => 'Remote', 'other' => 'Other' ) );
		add_option( 'ndwd_priority_labels', array() );
		add_option( 'ndwd_cron_key', wp_generate_password( 32, false ) );
		add_option( 'ndwd_reports_dir', 'ndwd-reports-' . wp_generate_password( 12, false ) );
		add_option( 'ndwd_settings', array(
			'email_enabled'        => 0,
			'email_from_name'      => get_bloginfo( 'name' ),
			'email_from'           => '',
			'email_types'          => array( 'assigned' => 1, 'reassigned' => 1, 'reminder' => 1, 'updated' => 0, 'daily_summary' => 0, 'monthly_report' => 1, 'leave' => 1 ),
			'report_email'         => '',
			'retention_months'     => 6,
			'daily_summary_hour'   => 9,
			'scheduler_health_minutes' => NDWD_Cron::HEALTH_MINUTES,
			'smtp_host'            => '',
			'smtp_port'            => 587,
			'smtp_secure'          => 'tls',
			'smtp_user'            => '',
			'session_until_midnight' => 0,
			'delete_on_uninstall'  => 0,
		), '', false );
	}
}
