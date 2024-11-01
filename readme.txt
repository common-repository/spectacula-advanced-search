=== Plugin Name ===
Contributors: interconnectit, spectacula
Donate link: https://spectacu.la/signup/signup.php
Tags: Search
Requires at least: 2.7.0
Tested up to: 2.9.2
Stable tag: 1.0.3

Changes the Wordpress search queries to provide more relevant results more
efficiently and with options to influence the results.

== Description ==

This plug-in provides you with more relevant search results than are currently
available to the normal Wordpress search and it should also do so with less of a
performance hit on the db than the normal Wordpress search. We use the MySQL
commands MATCH () AGAINST () as opposed to Wordpress’ use of LIKE to do our
queries which are not as heavy on the db. The only issue we have with “match
against” is that we need an index on the columns we intend to search on.

== Installation ==

= The install =

1.	Upload `spec-adv-search` folder to	`/wp-content/plugins/spec-adv-search/`
	or  the content of the folder to `/wp-content/mu-plugins/`.
	If the directory doesn't exist then create it.
2.	Activate the plugin through the 'Plugins' menu in WordPress.
3.	You should now see an extra menu Advanced search show up under the
	settings menu in the main admin sidebar.
4.	Go to the new page and hit the "Create Index" button. Once the index has
	been created you'll then be able to tick the enable box at the top of the
	page.

= The config =
1.	For these search methods to work you'll need a FULLTEXT index on your
	post_content and post_title in the wp_posts table. This can be done by using
	the "create index" button. There are some things to be aware of before you
	proceed.
	1.	Firstly adding the index will likely double the size of your wp_posts
	table, this in and of itself isn’t a problem however if you’re running up
	against	the upper limits of your hosts allowed size then creating an index
	may push you over that limit. If you do hit your size limit nothing bad
	should happen but it would be best if you backed up your database first just
	in case. Always a good idea to run a backup before anything is done to
	change the db.
	2.	Second thing you should know is that the index creation is handled by a
	Wordpress cron job. Some hosts have problems with wp_cron and if your host
	is one of those then you will see this “The index creation/deletion is
	scheduled to start after {date and time}” below the button for a long time
	after the job was supposed to run. Don’t worry if it’s only a few mins as
	that is quite normal. If you do have a problem with wp_cron on your server
	you may find future dated posts don’t become live when you hoped. We can, if
	you have something like PHPMyAdmin, create the indexes manually by running
	these commands against your Wordpress db:

	`CREATE FULLTEXT INDEX spec_post_content_fulltext ON wp_posts (post_content);`

	`CREATE FULLTEXT INDEX spec_post_content_fulltext_title ON wp_posts (post_title);`

	3.	Thirdly creating the indexes could take quite some time depending upon
	your server set up. Once the index creation kicks in, unless your server
	creates the index so quickly that it completes the creation before the
	command to collect the status has run, you should see “Creating index on
	post_-----. MySQL is returning the following message:” Don’t panic if you
	see something like “repair by sorting” or “copy to tmp table” as these are
	expected messages but if you get “Repair with keycache” still don’t panic
	just be prepared to wait a long while for the index to complete its
	creation.
2.	Once the index is live you should be able to tick the enable button. Now we
	get to choose from up to 5 different modes of search:
	1.	Default mode: This will find posts containing any of the term, the more
		of the terms there are in a post the higher relevance score it receives
		and thus the higher in the results it will appear (presuming sorting by
		rank). If two or more terms are searched for but only one can be found
		then posts matching that one will be returned. Terms matched in titles
		count as one and a half as much as those in the content.
	2.	Boolean mode: Very much the same as default mode but you get several
		extra operators that can alter a query. Adding + or – to the head of a
		term will either make it so that you return posts that must have the
		term or must not have the term. > or < will increase or decrease the
		importance of a word. So for example (phone >droid) would find all phone
		post and droid post but droid posts would be considered more important.
		A full explanation of the operators can be found here.
	3.	With query expansion: This kind of search can find posts related to your
		search terms but don’t necessarily contain any of the terms entered. So
		search for “android” for example and it will use posts that contain the
		word android to find words that are related to it. So it may deem that
		you want posts about robots too or it may also figure you want post
		relating to Google’s phone OS it really depends upon your sites content.
		For this mode you’ll need to tweak the relevance limit bases on what has
		been returned for your content otherwise it can end up returning all
		posts on your site. Not too big a problem if ordered by relevance but
		if ordered by date your results will make no sense at all.
	4.	In natural language mode: If you have MySQL 5.1 or better you will have
		two more modes available to you. First is In natural language mode which
		is mostly the same as default mode and in natural language more with
		query expansion which is functionally the same as the with query
		expansion.
3.	If your server works with wp_cron then you can set up a periodic table
	optimise that will help keep your table index in order. If your site changes
	rarely then you can set it to run infrequently or not at all and just use
	the “optimise table” button to do it as and when you want/need to. The more
	accurate your index is the better your search results will be and the lower
	the load on the db. All good basically. The optimise will kick in after the
	time it is set to run but wp-cron requires a visitor to kick off the job. So
	if you set it to start at 3:00am and you don’t get an activity on your site
	until 10am the next day the job won’t start until 10am. This shouldn’t be a
	problem as most optimisations apply very little load and take only a few
	second to run.

== Frequently Asked Questions ==

= With query expansion mode returns some odd results? =
	This mode guesses what the searcher was after from words MySQL thinks are
	related to the terms searched for. If your content implies an association
	between two otherwise unrelated terms then MySQL will make an assumption
	that they are related. For example if you mention Ducks and Strawberries
	together in a few post your index will see a relationship and searches for
	Ducks may return results for Strawberries.

== Changelog ==

= 1.0.4 =
*	Removed a deprecated call.

= 1.0.3 =
*	Moved out of beta and corrected a few typos.

= 1.0.2.beta =
*	Fixed it so that the ranking score shouldn't now show in anything that calls
	the_excerpt/content once the loop is complete. If you call before the loop
	then it likely still will.

= 1.0.1.beta =
*	Added help text and a few other fixes and tweaks.

= 1.0.beta =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
If you have the Beta version of this then it would be best if you got this one.
