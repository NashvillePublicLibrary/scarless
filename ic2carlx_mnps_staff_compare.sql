-- ic2carlx_mnps_staff_compare.sql
-- James Staub
-- Nashville Public Library
-- Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
-- TO DO: bug in SQLite 3.7.17 forces us to create the table before importing data into it. See https://stackoverflow.com/questions/38035543/sqlite3-import-csv-not-working
-- TO DO: update SQLite to version 3.24.0 (2018-06-04) to get UPSERT https://sqlite.org/lang_UPSERT.html
-- TO DO: determine the benefit and method of using prepare()
-- TO DO: for patron data privacy, kill this database when actions are complete

DROP TABLE IF EXISTS carlx;
CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,DefaultBranch,ExpirationDate,EmailAddress,EmailNotices,ExpiredNoteIDs,CollectionStatus);

DROP TABLE IF EXISTS infinitecampus;
CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,DefaultBranch,ExpirationDate,EmailAddress,EmailNotices,ExpiredNoteIDs,CollectionStatus);

.headers on
.mode csv
.import ../data/ic2carlx_mnps_staff_carlx.csv carlx
.import ../data/ic2carlx_mnps_staff_infinitecampus.csv infinitecampus

-- UPDATE PATRON SEEN
create table if not exists patron_seen (patronid,patron_seen);
update patron_seen 
set patron_seen = CURRENT_DATE 
where patronid in (
	select i.patronid 
	from infinitecampus i 
	inner join patron_seen p on i.patronid = p.patronid
); 

-- INSERT PATRON SEEN
insert into patron_seen 
select i.patronid, CURRENT_DATE 
from infinitecampus i 
left join patron_seen p on i.patronid = p.patronid 
where p.patronid is null;

-- "REMOVE" CARLX PATRON
create table if not exists carlx_remove (patronid,patron_seen,emailaddress,collectionstatus,defaultbranch,borrowertypecode);
delete 
from carlx_remove
;
insert into carlx_remove select p.patronid,
	p.patron_seen,
	c.emailaddress,
	c.collectionstatus,
	c.defaultbranch,
	c.borrowertypecode
from patron_seen p
left join carlx c on p.patronid = c.PatronID
where patron_seen < date('now','-7 days')
; 
.headers on
.output ../data/ic2carlx_mnps_staff_remove.csv
select * from carlx_remove;
.output stdout

delete
from patron_seen 
where patron_seen < date('now','-7 days')
; 

-- CREATE CARLX PATRON
create table if not exists carlx_create (patronid,borrowertypecode,patronlastname,patronfirstname,patronmiddlename,patronsuffix,defaultbranch,expirationdate,emailaddress,emailnotices,expirednoteids,collectionstatus);
delete 
from carlx_create
;
insert into carlx_create select infinitecampus.*
from infinitecampus
left join carlx on infinitecampus.PatronID = carlx.PatronID
where carlx.PatronID IS NULL
order by infinitecampus.PatronID
;
.headers on
.output ../data/ic2carlx_mnps_staff_create.csv
select * from carlx_create;
.output stdout

-- UPDATE CARLX PATRON (DO NOT IGNORE EMAIL)
create table if not exists carlx_update (patronid,borrowertypecode,patronlastname,patronfirstname,patronmiddlename,patronsuffix,defaultbranch,expirationdate,emailaddress,emailnotices,collectionstatus);
delete 
from carlx_update
;
insert into carlx_update select PatronID,
	Borrowertypecode,
	Patronlastname,
	Patronfirstname,
	Patronmiddlename,
	Patronsuffix,
	DefaultBranch,
	ExpirationDate,
	EmailAddress,
	EmailNotices,
	CollectionStatus
from infinitecampus
except
select carlx.PatronID,
	carlx.Borrowertypecode,
	carlx.Patronlastname,
	carlx.Patronfirstname,
	carlx.Patronmiddlename,
	carlx.Patronsuffix,
	carlx.DefaultBranch,
	carlx.ExpirationDate,
	carlx.EmailAddress,
	carlx.EmailNotices,
	carlx.CollectionStatus
from carlx
except
	select 	infinitecampus.PatronID,
		infinitecampus.Borrowertypecode,
		infinitecampus.Patronlastname,
		infinitecampus.Patronfirstname,
		infinitecampus.Patronmiddlename,
		infinitecampus.Patronsuffix,
		infinitecampus.DefaultBranch,
		infinitecampus.ExpirationDate,
		infinitecampus.EmailAddress,
		infinitecampus.EmailNotices,
		infinitecampus.CollectionStatus
	from infinitecampus
	left join carlx on infinitecampus.PatronID = carlx.PatronID
	where carlx.PatronID IS NULL
	order by infinitecampus.PatronID
;
.headers on
.output ../data/ic2carlx_mnps_staff_update.csv
select * from carlx_update;
.output stdout

-- Delete Expired MNPS Patron Notes when patron re-appears in Infinite Campus
.headers on
.output ../data/ic2carlx_mnps_staff_deleteExpiredNotes.csv
select c.PatronID, 
	c.ExpiredNoteIDs 
from infinitecampus i 
inner join carlx c on i.patronid = c.patronid 
where c.ExpiredNoteIDs != ""
;
.output stdout

-- REPORT x BRANCH
create table if not exists report_defaultbranch (
	date,
	defaultbranch,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
);
delete from report_defaultbranch
where date = CURRENT_DATE;
insert into report_defaultbranch 
select CURRENT_DATE as date, 
	x.defaultbranch,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
from (
	select defaultbranch,
	count(patronid) as carlx
	from carlx
	group by defaultbranch
	order by defaultbranch
) x 
left outer join (
	select defaultbranch,
	count(patronid) as infinitecampus
	from infinitecampus
	group by defaultbranch
	order by defaultbranch
) i on x.defaultbranch = i.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as created
	from carlx_create 
	group by defaultbranch
	order by defaultbranch
) c on x.defaultbranch = c.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as updated
	from carlx_update 
	group by defaultbranch
	order by defaultbranch
) u on x.defaultbranch = u.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as removed
	from carlx_remove 
	group by defaultbranch
	order by defaultbranch
) r on x.defaultbranch = r.defaultbranch
;
.headers on
.output ../data/ic2carlx_mnps_staff_report_defaultbranch.csv
select defaultbranch,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
from report_defaultbranch
where report_defaultbranch.date = CURRENT_DATE
;
.output stdout

-- REPORT x BRANCH : ABORT PATRON LOAD
.headers on
.output ../data/ic2carlx_mnps_staff_report_defaultbranch_ABORT.csv
select *
from report_defaultbranch
where date = CURRENT_DATE
and (infinitecampus <= carlx*.9
	or created >= carlx*.1
	or updated >= carlx*.1
	or removed >= carlx*.1
)
;
.output stdout

-- REPORT x BORROWER TYPE
create table if not exists report_borrowertypecode (
	date,
	borrowertypecode,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
);
delete from report_borrowertypecode
where date = CURRENT_DATE;
insert into report_borrowertypecode
select CURRENT_DATE as date, 
	x.borrowertypecode,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
from (
	select borrowertypecode,
	count(patronid) as carlx
	from carlx
	group by borrowertypecode
	order by borrowertypecode
) x 
left outer join (
	select borrowertypecode,
	count(patronid) as infinitecampus
	from infinitecampus
	group by borrowertypecode
	order by borrowertypecode
) i on x.borrowertypecode = i.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as created
	from carlx_create 
	group by borrowertypecode
	order by borrowertypecode
) c on x.borrowertypecode = c.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as updated
	from carlx_update 
	group by borrowertypecode
	order by borrowertypecode
) u on x.borrowertypecode = u.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as removed
	from carlx_remove 
	group by borrowertypecode
	order by borrowertypecode
) r on x.borrowertypecode = r.borrowertypecode
;
.headers on
.output ../data/ic2carlx_mnps_staff_report_borrowertypecode.csv
select borrowertypecode,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
from report_borrowertypecode
where report_borrowertypecode.date = CURRENT_DATE
;
.output stdout

-- REPORT x BORROWER TYPE : ABORT PATRON LOAD
.headers on
.output ../data/ic2carlx_mnps_staff_report_borrowertypecode_ABORT.csv
select *
from report_borrowertypecode
where date = CURRENT_DATE
and (infinitecampus <= carlx*.9
	or created >= carlx*.1
	or updated >= carlx*.1
	or removed >= carlx*.1
)
;
.output stdout
